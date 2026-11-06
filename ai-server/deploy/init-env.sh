#!/usr/bin/env bash
# Creates .env for the Docker deployment: asks 3 questions, generates the secrets.
#   bash deploy/init-env.sh
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -f .env ]; then
  echo ".env already exists. Delete it first if you want to generate a new one." >&2
  exit 1
fi

rand_hex() {
  if command -v openssl >/dev/null 2>&1; then openssl rand -hex "$1"
  else python3 -c "import secrets,sys; print(secrets.token_hex(int(sys.argv[1])))" "$1"
  fi
}

# set_kv KEY VALUE  -> replaces the KEY= line in .env (value is passed through the environment,
# so characters like / & | \ are safe)
set_kv() {
  V="$2" awk -v k="$1" '
    BEGIN { v = ENVIRON["V"] }
    index($0, k "=") == 1 { print k "=" v; done = 1; next }
    { print }
    END { if (!done) print k "=" v }
  ' .env > .env.tmp && mv .env.tmp .env
}

read -rp "Server domain (e.g. ai.example.com): " DOMAIN
read -rp "WordPress site address (e.g. https://your-site.com): " WP_URL
read -rsp "OpenAI API key (sk-...): " OPENAI_KEY; echo

DOMAIN="${DOMAIN#http://}"; DOMAIN="${DOMAIN#https://}"; DOMAIN="${DOMAIN%%/*}"
WP_URL="${WP_URL%/}"
[ -n "$DOMAIN" ] && [ -n "$WP_URL" ] && [ -n "$OPENAI_KEY" ] || { echo "All three values are required." >&2; exit 1; }
case "$WP_URL" in http://*|https://*) ;; *) echo "WordPress address must start with https://" >&2; exit 1;; esac

API_SECRET="$(rand_hex 32)"   # 64 hex chars
QDRANT_KEY="$(rand_hex 24)"

cp .env.example .env
set_kv DOMAIN "$DOMAIN"
set_kv APP_ENV production
set_kv REQUIRE_HTTPS true
set_kv QDRANT_MODE server
set_kv QDRANT_PATH ""
set_kv QDRANT_API_KEY "$QDRANT_KEY"
set_kv API_SHARED_SECRET "$API_SECRET"
set_kv PLUGIN_BASE_URL "$WP_URL"
set_kv OPENAI_API_KEY "$OPENAI_KEY"
chmod 600 .env

cat <<MSG

.env created.

Add these two lines to wp-config.php of your WordPress site
(above the line "That's all, stop editing"):

  define( 'PWC_API_URL',    'https://$DOMAIN' );
  define( 'PWC_API_SECRET', '$API_SECRET' );

Next:  docker compose up -d --build
MSG
