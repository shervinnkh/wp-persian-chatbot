"""Messaging endpoints: save a chat message (or an ordered batch), delete one message, clear a conversation."""

from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, BackgroundTasks, Depends, Path, Request

from app.schemas.common import ErrorResponse
from app.schemas.messaging import (
    AiAnswerOut,
    BatchItemOut,
    ClearMessagesResponse,
    DeleteMessageResponse,
    MessageOut,
    ReferenceOut,
    SendMessageRequest,
    SendMessageResponse,
    SendMessagesBatchRequest,
    SendMessagesBatchResponse,
)
from app.services.conversation.answer import AiAnswerResult
from app.services.messaging import MessagingService
from app.services.ratelimit import hash_ip

router = APIRouter(tags=["messaging"])

_RESPONSES = {
    401: {"model": ErrorResponse},
    403: {"model": ErrorResponse},
    404: {"model": ErrorResponse},
    429: {"model": ErrorResponse},  # rate_limited | budget_exceeded (Retry-After)
    503: {"model": ErrorResponse},  # busy (answer queue full)
}


def _ip_hash(request: Request, client_ip: str | None) -> str | None:
    """HMAC of the visitor's IP (S-1). Also put on the request so the access/monitoring event of
    this call carries the hash instead of the raw address."""
    value = hash_ip(request.app.state.settings, client_ip)
    request.state.ip_hash = value
    return value


def get_messaging_service(request: Request) -> MessagingService:
    """Built lazily on first use (tests inject their own via create_app)."""
    service = getattr(request.app.state, "messaging_service", None)
    if service is None:
        service = MessagingService.from_settings(request.app.state.settings)
        request.app.state.messaging_service = service
    return service


Service = Annotated[MessagingService, Depends(get_messaging_service)]


def _ai_message_out(ai: AiAnswerResult) -> MessageOut | None:
    if not ai.message:
        return None
    out = MessageOut.model_validate(ai.message)
    out.references = [ReferenceOut(**r) for r in ai.references]
    return out


def _ai_out(ai: AiAnswerResult) -> AiAnswerOut:
    return AiAnswerOut(
        status=ai.status,
        marked=ai.marked,
        message=_ai_message_out(ai),
        full_history_tokens=ai.full_history_tokens,
        context_tokens=ai.context_tokens,
        summarization_scheduled=ai.summarization_scheduled,
        model=ai.model,
        job_id=ai.job_id,
    )


@router.post(
    "/chat/messages",
    response_model=SendMessageResponse,
    responses=_RESPONSES,
    summary="Save a message; generate_ai_answer=true on a user message also produces an AI reply "
    "(with a callback: queued, the answer is POSTed to the callback when ready)",
)
async def send_message(
    body: SendMessageRequest, service: Service, background: BackgroundTasks, request: Request
) -> SendMessageResponse:
    result = await service.send(body, ip_hash=_ip_hash(request, body.client_ip))
    ai = result.ai
    if ai.summarize_upto_message_id is not None:
        # Runs AFTER the response reached the client, so the visitor never waits for it.
        background.add_task(
            service.enqueue_summary, result.conversation_id, ai.summarize_upto_message_id
        )
    return SendMessageResponse(
        conversation_id=result.conversation_id,
        created_conversation=result.created_conversation,
        attachment_status=result.attachment_status,
        message=MessageOut.model_validate(result.message),
        ai_answer=_ai_out(ai),
    )


@router.post(
    "/chat/messages/batch",
    response_model=SendMessagesBatchResponse,
    responses=_RESPONSES,
    summary="Save a LIST of messages in order (oldest first); generate_ai_answer=true also answers "
    "the last one (must be a user message). `received_count` = messages saved from the start of the list",
)
async def send_messages_batch(
    body: SendMessagesBatchRequest, service: Service, background: BackgroundTasks, request: Request
) -> SendMessagesBatchResponse:
    result = await service.send_batch(body, ip_hash=_ip_hash(request, body.client_ip))
    ai = result.ai
    if ai.summarize_upto_message_id is not None:
        background.add_task(
            service.enqueue_summary, result.conversation_id, ai.summarize_upto_message_id
        )
    return SendMessagesBatchResponse(
        conversation_id=result.conversation_id,
        created_conversation=result.created_conversation,
        total_count=result.total_count,
        received_count=result.received_count,
        error=result.error,
        saved_callback_queued=result.saved_callback_queued,
        messages=[
            BatchItemOut(
                index=i,
                external_id=body.messages[i].external_id,
                attachment_status=item.attachment_status,
                message=MessageOut.model_validate(item.message),
            )
            for i, item in enumerate(result.items)
        ],
        ai_answer=_ai_out(ai),
    )


@router.delete(
    "/conversations/{conversation_id}/messages/{message_id}",
    response_model=DeleteMessageResponse,
    responses=_RESPONSES,
    summary="Delete one message (and its attachment file)",
)
async def delete_message(
    service: Service,
    conversation_id: Annotated[int, Path(ge=1)],
    message_id: Annotated[int, Path(ge=1)],
) -> DeleteMessageResponse:
    await service.delete_message(conversation_id, message_id)
    return DeleteMessageResponse(conversation_id=conversation_id, message_id=message_id)


@router.delete(
    "/conversations/{conversation_id}/messages",
    response_model=ClearMessagesResponse,
    responses=_RESPONSES,
    summary="Delete ALL messages (and files) of a conversation; the conversation itself stays",
)
async def clear_messages(
    service: Service, conversation_id: Annotated[int, Path(ge=1)]
) -> ClearMessagesResponse:
    count = await service.clear_messages(conversation_id)
    return ClearMessagesResponse(conversation_id=conversation_id, deleted_count=count)
