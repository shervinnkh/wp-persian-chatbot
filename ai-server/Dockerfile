# Persian WP Chatbot Server - production image (API + workers share it, only the command differs)
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    TIKTOKEN_CACHE_DIR=/opt/tiktoken_cache

RUN groupadd -r -g 10001 app && useradd -r -u 10001 -g app -d /app -s /usr/sbin/nologin app

WORKDIR /app

# tzdata: BUDGET_TIMEZONE=Asia/Tehran needs the time zone database
COPY requirements.txt ./
RUN pip install -r requirements.txt tzdata

# Download the tiktoken vocabulary at build time (so the first start does not need internet).
# Not fatal: if it fails here, the app downloads it on first start.
RUN mkdir -p "$TIKTOKEN_CACHE_DIR" \
    && (python -c "import tiktoken; tiktoken.get_encoding('cl100k_base')" \
        || echo "tiktoken prefetch skipped") \
    && mkdir -p /app/data /app/uploads /app/logs \
    && chown -R app:app /app /opt/tiktoken_cache

COPY --chown=app:app app ./app

USER app
EXPOSE 8000

CMD ["python", "-m", "app"]
