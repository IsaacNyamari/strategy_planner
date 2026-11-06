#!/usr/bin/env bash
#
# Strategy Planner: ONE-TIME setup on an Ubuntu server (run as root).
#
#     bash setup-server.sh planner.humsols.com
#
# Safe on a server that already hosts other sites:
#   - only installs packages that are MISSING (never upgrades/restarts what's there),
#   - never touches other Nginx sites or the default site,
#   - creates its own database + user, its own Nginx site, its own certificate.
#
# It clones the repo to /var/www/strategy_planner, writes .env, configures Nginx
# for the domain, gets a Let's Encrypt certificate (HTTP -> HTTPS redirect) and
# runs the first ./deploy.sh. Re-running is safe: existing pieces are kept.
#
# The domain's DNS A record must already point to this server.
#
set -Eeuo pipefail

DOMAIN="${1:?Usage: setup-server.sh <domain>   e.g. setup-server.sh planner.humsols.com}"
REPO_URL="${REPO_URL:-https://github.com/IsaacNyamari/strategy_planner.git}"
APP_DIR="${APP_DIR:-/var/www/strategy_planner}"
BRANCH="${BRANCH:-main}"
PHP_VERSION="8.3"
DB_NAME="strategy_planner"
DB_USER="strategy_planner"
SITE="/etc/nginx/sites-available/$DOMAIN"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
[ "$(id -u)" = 0 ] || { echo "Run as root." >&2; exit 1; }
export DEBIAN_FRONTEND=noninteractive

step "Packages (installing only what is missing)"
need=()
command -v git >/dev/null      || need+=(git)
command -v unzip >/dev/null    || need+=(unzip)
command -v nginx >/dev/null    || need+=(nginx)
command -v mysql >/dev/null    || need+=(mysql-server)
command -v certbot >/dev/null  || need+=(certbot python3-certbot-nginx)
command -v "php$PHP_VERSION" >/dev/null || need+=("php$PHP_VERSION-cli" "php$PHP_VERSION-fpm")
for ext in mysql mbstring xml zip gd curl intl bcmath; do
    dpkg -s "php$PHP_VERSION-$ext" >/dev/null 2>&1 || need+=("php$PHP_VERSION-$ext")
done
if [ ${#need[@]} -gt 0 ]; then
    echo "Installing: ${need[*]}"
    apt-get update -q
    apt-get install -y -q --no-upgrade "${need[@]}"
else
    echo "All required packages already present."
fi
if ! command -v composer >/dev/null; then
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    [ "$expected" = "$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" ] || { echo "Composer checksum mismatch" >&2; exit 1; }
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer && rm -f /tmp/composer-setup.php
fi
node_major="$(node -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/' || echo 0)"
if [ "${node_major:-0}" -lt 20 ]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt-get install -y -q nodejs
fi

step "DNS check"
resolved="$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}')"
echo "$DOMAIN -> ${resolved:-not resolving}"

step "Database"
if [ ! -f /root/.strategy_planner_db ]; then
    DB_PASS="$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 28)"
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
    (umask 077; echo "$DB_PASS" > /root/.strategy_planner_db)
    echo "Created database $DB_NAME and user $DB_USER (password in /root/.strategy_planner_db)."
fi
DB_PASS="$(cat /root/.strategy_planner_db)"

step "Code"
if [ ! -d "$APP_DIR/.git" ]; then
    git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"
chmod +x deploy.sh

step "Environment (.env)"
set_env() { # set_env KEY VALUE: replace or append in .env
    local key="$1" value="$2"
    if grep -qE "^#?\s*${key}=" .env; then
        sed -i -E "s|^#?\s*${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}
if [ ! -f .env ]; then
    cp .env.example .env
    set_env APP_NAME '"Strategy Planner"'
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "https://$DOMAIN"
    set_env LOG_LEVEL warning
    set_env DB_CONNECTION mysql
    set_env DB_HOST 127.0.0.1
    set_env DB_PORT 3306
    set_env DB_DATABASE "$DB_NAME"
    set_env DB_USERNAME "$DB_USER"
    set_env DB_PASSWORD "$DB_PASS"
    set_env SESSION_SECURE_COOKIE true
fi
chown root:www-data .env
chmod 640 .env

composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
grep -qE '^APP_KEY=base64:' .env || php artisan key:generate --force

step "Nginx site for $DOMAIN"
if [ ! -f "$SITE" ]; then
cat > "$SITE" <<NGINX
# Strategy Planner (praise & worship team app). Managed by deploy/setup-server.sh
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $APP_DIR/public;
    index index.php;
    charset utf-8;
    client_max_body_size 20M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    gzip on;
    gzip_comp_level 6;
    gzip_min_length 1024;
    gzip_types text/css application/javascript application/json image/svg+xml text/plain;

    access_log /var/log/nginx/$DOMAIN.access.log;
    error_log  /var/log/nginx/$DOMAIN.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Content-hashed build assets: cache for a year.
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php$PHP_VERSION-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_read_timeout 120s; # AI requests can take a while
        fastcgi_hide_header X-Powered-By;
    }

    location ~ \.php$ { return 404; }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
fi
ln -sf "$SITE" "/etc/nginx/sites-enabled/$DOMAIN"
nginx -t
systemctl reload nginx

step "SSL certificate"
if [ -d "/etc/letsencrypt/live/$DOMAIN" ]; then
    echo "Certificate already exists."
    certbot install --nginx --cert-name "$DOMAIN" -d "$DOMAIN" --redirect --non-interactive >/dev/null 2>&1 || true
else
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect --no-eff-email \
        $(certbot show_account >/dev/null 2>&1 || echo "--register-unsafely-without-email")
fi
nginx -t && systemctl reload nginx

step "Scheduler (cron)"
echo "* * * * * www-data cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/strategy_planner

step "First deploy"
./deploy.sh "$BRANCH"

cat <<DONE

Setup complete: https://$DOMAIN
  App folder: $APP_DIR
  DB password: /root/.strategy_planner_db (root only)

Future updates:  cd $APP_DIR && ./deploy.sh
DONE
