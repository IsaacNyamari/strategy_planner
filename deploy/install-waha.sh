#!/usr/bin/env bash
#
# One-time: run WAHA (WhatsApp HTTP API, https://waha.devlike.pro) for the app.
# Run as root on the server:
#
#     bash /var/www/strategy_planner/deploy/install-waha.sh 0711777992
#
# - installs Docker if missing (never upgrades an existing install),
# - runs WAHA (GOWS engine, no browser) bound to 127.0.0.1:3100 ONLY,
#   so it is never reachable from the internet,
# - protects it with a random API key (WAHA stores only its sha512 hash),
# - writes the WhatsApp settings into the app's .env and re-caches config.
#
# Safe to re-run: keeps the existing API key, webhook secret and linked session
# (sessions live in /var/lib/waha/sessions). Pass --upgrade to pull a newer image.
#
set -Eeuo pipefail

NUMBER="${1:?Usage: install-waha.sh <team WhatsApp number, e.g. 0711777992> [--upgrade]}"
UPGRADE="${2:-}"
APP_DIR="/var/www/strategy_planner"
CONTAINER="waha"
IMAGE="devlikeapro/waha:gows"
PORT="3100"
DATA="/var/lib/waha"
SECRETS="/root/.waha_secrets"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
[ "$(id -u)" = 0 ] || { echo "Run as root." >&2; exit 1; }

step "Docker"
if ! command -v docker >/dev/null; then
    apt-get update -q
    DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-upgrade docker.io
fi
systemctl enable --now docker >/dev/null
docker --version

step "Secrets"
if [ ! -f "$SECRETS" ]; then
    (umask 077; {
        echo "WAHA_API_KEY=$(openssl rand -hex 32)"
        echo "WAHA_WEBHOOK_SECRET=$(openssl rand -hex 32)"
    } > "$SECRETS")
    echo "Generated new API key and webhook secret (stored root-only in $SECRETS)."
else
    echo "Using existing secrets from $SECRETS."
fi
# shellcheck disable=SC1090
. "$SECRETS"
API_HASH="$(printf '%s' "$WAHA_API_KEY" | sha512sum | cut -d' ' -f1)"

step "WAHA container"
mkdir -p "$DATA/sessions"
if [ "$UPGRADE" = "--upgrade" ] || ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    docker pull -q "$IMAGE"
fi
if docker ps -a --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    docker rm -f "$CONTAINER" >/dev/null # recreated with current settings; sessions are kept on disk
fi
docker run -d --name "$CONTAINER" --restart unless-stopped \
    -p "127.0.0.1:$PORT:3000" \
    -v "$DATA/sessions:/app/.sessions" \
    -e "WAHA_API_KEY=sha512:$API_HASH" \
    -e "WHATSAPP_DEFAULT_ENGINE=GOWS" \
    -e "WAHA_DASHBOARD_ENABLED=false" \
    -e "WHATSAPP_SWAGGER_ENABLED=false" \
    -e "WAHA_LOG_LEVEL=warn" \
    --memory 1g \
    "$IMAGE" >/dev/null

echo -n "Waiting for WAHA to answer"
for _ in $(seq 1 30); do
    if curl -fsS -H "X-Api-Key: $WAHA_API_KEY" "http://127.0.0.1:$PORT/api/sessions?all=true" >/dev/null 2>&1; then
        echo " ok"; ok=1; break
    fi
    echo -n "."; sleep 2
done
[ "${ok:-0}" = 1 ] || { echo; docker logs --tail 30 "$CONTAINER"; echo "WAHA did not start." >&2; exit 1; }

echo "Public access check (must fail): $(curl -s -o /dev/null -m 5 -w '%{http_code}' "http://$(hostname -I | awk '{print $1}'):$PORT/" || true) (000 = not reachable, good)"

step "App settings (.env)"
cd "$APP_DIR"
set_env() {
    if grep -qE "^$1=" .env; then sed -i -E "s|^$1=.*|$1=$2|" .env; else printf '%s=%s\n' "$1" "$2" >> .env; fi
}
set_env WHATSAPP_ENABLED true
set_env WAHA_URL "http://127.0.0.1:$PORT"
set_env WAHA_API_KEY "$WAHA_API_KEY"
set_env WAHA_WEBHOOK_SECRET "$WAHA_WEBHOOK_SECRET"
set_env WAHA_SESSION default
set_env WHATSAPP_NUMBER "$NUMBER"
chown root:www-data .env && chmod 640 .env
php artisan config:cache >/dev/null

cat <<DONE

WAHA is running (local only) and the app is configured.
Next: open $(grep -E '^APP_URL=' .env | cut -d= -f2)/whatsapp
      -> Start connection -> link $NUMBER with a pairing code or QR
      -> Load from WhatsApp -> add your groups/channels -> Test.
DONE
