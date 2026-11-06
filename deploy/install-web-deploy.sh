#!/usr/bin/env bash
#
# One-time: enable the "Deploy" button in the app. Run as root on the server:
#
#     bash /var/www/strategy_planner/deploy/install-web-deploy.sh you@example.com [other@example.com ...]
#
# - installs the helper to /usr/local/bin/strategy-planner-deploy (root-owned,
#   so the web server cannot change what it runs),
# - adds a sudo rule letting www-data run ONLY that helper's "run" and "status",
# - turns the feature on in .env for the given admin email(s).
#
# Re-run it after deploy/web-deploy.sh changes, or to change the admin list.
# To disable: set WEB_DEPLOY_ENABLED=false in .env and run ./deploy.sh
#
set -Eeuo pipefail

APP_DIR="/var/www/strategy_planner"
HELPER="/usr/local/bin/strategy-planner-deploy"
SUDOERS="/etc/sudoers.d/strategy-planner-deploy"

[ "$(id -u)" = 0 ] || { echo "Run as root." >&2; exit 1; }
[ $# -ge 1 ] || { echo "Usage: $0 admin@example.com [more admins...]" >&2; exit 1; }
command -v flock >/dev/null || { echo "flock is missing (apt install util-linux)." >&2; exit 1; }

admins="$(IFS=,; echo "$*")"

install -o root -g root -m 0755 "$APP_DIR/deploy/web-deploy.sh" "$HELPER"
echo "Installed $HELPER"

tmp="$(mktemp)"
cat > "$tmp" <<SUDO
# Strategy Planner: allow the web app to trigger deployments (and nothing else).
www-data ALL=(root) NOPASSWD: $HELPER run *, $HELPER status
SUDO
visudo -cf "$tmp" >/dev/null
install -o root -g root -m 0440 "$tmp" "$SUDOERS"
rm -f "$tmp"
echo "Installed sudo rule $SUDOERS"

cd "$APP_DIR"
set_env() {
    if grep -qE "^$1=" .env; then sed -i -E "s|^$1=.*|$1=$2|" .env; else printf '%s=%s\n' "$1" "$2" >> .env; fi
}
set_env WEB_DEPLOY_ENABLED true
set_env WEB_DEPLOY_ADMINS "$admins"
php artisan config:cache >/dev/null

echo "Checking the web server can call the helper..."
sudo -u www-data sudo -n "$HELPER" status

echo
echo "Done. Admins who can deploy from the app: $admins"
echo "Open: $(grep -E '^APP_URL=' .env | cut -d= -f2)/deployments"
