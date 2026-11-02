"""Async AI answers: queue -> answer loop -> callback delivery (fake transport, temp SQLite)."""

from __future__ import annotations

import asyncio
import json

from sqlalchemy import select, update

from app.db.answer_models import AnswerJob
from app.db.base import Database
from app.db.ingest_models import CallbackDelivery, CallbackSite
from app.db.ingest_repository import IngestRepository
from app.db.messaging_models import utcnow
from app.schemas.messaging import SendMessageRequest
from app.services.conversation.answer import FIXED_ANSWER
from app.services.ingestion.delivery import DeliveryService, HttpResult
from app.services.messaging import MessagingService
from tests.conftest import make_settings

CB = {"url": "http://wp.test/wp-json/paia/v1/ai/results", "ping_url": "http://wp.test/ping"}


class Fake:
    def __init__(self, *answers):
        self.answers, self.calls = list(answers), []

    async def request(self, method, url, body, headers):
        self.calls.append((method, url, body))
        return self.answers.pop(0) if len(self.answers) > 1 else self.answers[0]


OK = HttpResult(200, b"{}", {}, elapsed_ms=20)
BAD = HttpResult(500, b"{}", {}, elapsed_ms=20)


def _req(**extra):
    return SendMessageRequest(
        visitor_uid="v1", role="user", content="سلام", generate_ai_answer=True, callback=CB, **extra
    )


async def _make(tmp_path, **overrides):
    values = dict(
        CALLBACK_ALLOW_PRIVATE_NETWORKS=True,
        CALLBACK_BATCH_WINDOW_SECONDS=0,
        CALLBACK_MIN_INTERVAL_SECONDS=0,
        ANSWER_WORKER_POLL_SECONDS=0.1,
        ANSWER_RETRY_DELAY_SECONDS=1,
    )
    values.update(overrides)
    settings = make_settings(tmp_path, **values)
    service = MessagingService(settings=settings, db=Database.from_settings(settings))
    await service.ensure_ready()
    return settings, service


async def _wait(cond, timeout=8.0):
    end = asyncio.get_running_loop().time() + timeout
    while asyncio.get_running_loop().time() < end:
        if await cond():
            return True
        await asyncio.sleep(0.05)
    return False


async def _job(service):
    async with service.db.session() as s:
        return (await s.execute(select(AnswerJob))).scalars().first()


async def _deliveries(service):
    async with service.db.session() as s:
        return list((await s.execute(select(CallbackDelivery))).scalars().all())


def test_queued_answer_is_generated_and_delivered(tmp_path):
    async def main():
        settings, service = await _make(tmp_path)
        stop = asyncio.Event()
        loop_task = asyncio.create_task(service.answers.run_loop(stop))
        try:
            res = await service.send(_req(model="zzz"))
            assert res.ai.status == "queued" and res.ai.job_id and res.ai.message is None
            assert res.ai.model == "gpt-5-4"  # not in the allow-list -> default

            async def done():
                j = await _job(service)
                return j is not None and j.status == "done"

            assert await _wait(done)
            rows = await _deliveries(service)
            assert len(rows) == 1 and rows[0].kind == "answer" and rows[0].state == "pending"

            fake = Fake(OK)
            delivery = DeliveryService(
                settings=settings, repo=IngestRepository(service.db), transport=fake
            )
            await delivery.run_pass(wait=True)
            assert len(fake.calls) == 1
            body = json.loads(fake.calls[0][2])
            item = body["results"][0]
            assert item["kind"] == "chat_answer" and item["status"] == "answered"
            assert item["visitor_uid"] == "v1" and item["message"]["content"] == FIXED_ANSWER
            assert item["question_message_id"] == res.message.id
            assert (await _deliveries(service))[0].state == "done"
        finally:
            stop.set()
            service.answers.notify()
            await loop_task
            await service.close()

    asyncio.run(main())


def test_no_callback_still_answers_inside_the_request(tmp_path):
    async def main():
        _, service = await _make(tmp_path)
        try:
            req = SendMessageRequest(
                visitor_uid="v2", role="user", content="سلام", generate_ai_answer=True
            )
            res = await service.send(req)
            assert res.ai.status == "generated" and res.ai.message.content == FIXED_ANSWER
        finally:
            await service.close()

    asyncio.run(main())


def test_failed_generation_retries_then_reports_failure(tmp_path):
    async def main():
        settings, service = await _make(tmp_path, ANSWER_MAX_ATTEMPTS=2)

        class Boom:
            async def generate(self, history, ctx=None):
                raise RuntimeError("llm down")

        service.answer_generator = Boom()
        stop = asyncio.Event()
        loop_task = asyncio.create_task(service.answers.run_loop(stop))
        try:
            await service.send(_req())

            async def failed():
                j = await _job(service)
                return j is not None and j.status == "failed"

            assert await _wait(failed, timeout=10)
            assert (await _job(service)).attempts == 2
            rows = await _deliveries(service)
            assert len(rows) == 1
            item = json.loads(rows[0].payload)
            assert item["status"] == "failed" and item["kind"] == "chat_answer"
        finally:
            stop.set()
            service.answers.notify()
            await loop_task
            await service.close()

    asyncio.run(main())


def test_undeliverable_answer_stops_after_the_attempt_limit(tmp_path):
    async def main():
        settings, service = await _make(tmp_path, ANSWER_CALLBACK_MAX_ATTEMPTS=3)
        stop = asyncio.Event()
        loop_task = asyncio.create_task(service.answers.run_loop(stop))
        try:
            await service.send(_req())

            async def has_row():
                return bool(await _deliveries(service))

            assert await _wait(has_row)
            fake = Fake(BAD)
            delivery = DeliveryService(
                settings=settings, repo=IngestRepository(service.db), transport=fake
            )
            for _ in range(5):  # backoff is skipped by resetting the site clock each time
                async with service.db.session() as s:
                    await s.execute(
                        update(CallbackSite).values(next_attempt_at=None, state="healthy")
                    )
                    await s.execute(
                        update(CallbackDelivery)
                        .where(CallbackDelivery.state == "pending")
                        .values(available_at=utcnow())
                    )
                    await s.commit()
                await delivery.run_pass(wait=True)
            row = (await _deliveries(service))[0]
            assert row.state == "dead" and row.attempts == 3
            assert len(fake.calls) == 3  # no more sends after it died
        finally:
            stop.set()
            service.answers.notify()
            await loop_task
            await service.close()

    asyncio.run(main())
