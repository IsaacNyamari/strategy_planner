#!/usr/bin/env bash
#
# Strategy Planner: update the live site from GitHub.
#
# On the server, from the app folder (default /var/www/strategy_planner):
#
#     ./deploy.sh            # deploy the latest commit on main
#     ./deploy.sh my-branch  # deploy another branch
#
# What it does: maintenance mode on -> git pull -> composer + npm build ->
# database migrations -> rebuild caches -> fix permissions -> reload PHP ->
# maintenance mode off. If any step fails, the site is brought back up and
# the script stops with an error.
#
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BRANCH="${1:-main}"
WEB_USER="${WEB_USER:-www-data}"

cd "$APP_DIR"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mDeploy failed: %s\033[0m\n' "$*" >&2; exit 1; }

[ -f .env ] || fail ".env is missing. Run deploy/setup-server.sh first (one-time setup)."
command -v composer >/dev/null || fail "composer is not installed."
command -v npm >/dev/null || fail "npm (Node.js) is not installed."

# Always bring the site back up, even if a step fails half-way.
in_maintenance=0
finish() {
    if [ "$in_maintenance" = 1 ]; then
        php artisan up >/dev/null 2>&1 || true
    fi
}
trap finish EXIT

step "Maintenance mode on"
php artisan down --retry=15 --refresh=15 >/dev/null 2>&1 && in_maintenance=1 || true

step "Pulling latest code ($BRANCH)"
git fetch --prune origin "$BRANCH"
# Server copy must match GitHub exactly: refuse to deploy over local edits.
if ! git diff --quiet || ! git diff --cached --quiet; then
    git status --short
    fail "the server has local code changes (listed above). Commit them on your PC instead, or discard with: git checkout -- ."
fi
git checkout -q "$BRANCH"
git reset --hard -q "origin/$BRANCH"
echo "Now at: $(git log -1 --format='%h %s (%cr)')"

step "Installing PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist

step "Building front-end assets"
npm ci --no-audit --no-fund --loglevel=error
npm run build

step "Running database migrations"
php artisan migrate --force

step "Rebuilding caches"
php artisan optimize:clear >/dev/null
php artisan storage:link >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

step "Fixing permissions"
chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

step "Reloading PHP"
for svc in $(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
    systemctl reload "$svc" && echo "reloaded $svc"
done
php artisan queue:restart >/dev/null 2>&1 || true

step "Maintenance mode off"
php artisan up
in_maintenance=0

printf '\n\033[1;32mDeployed %s successfully.\033[0m\n' "$(git log -1 --format='%h')"
