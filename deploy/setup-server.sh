#!/usr/bin/env bash
#
# Strategy Planner: ONE-TIME setup of a fresh Ubuntu/Debian server.
# Run as root:
#
#     bash setup-server.sh                      # site served on the server's IP
#     bash setup-server.sh planner.example.com  # or on a domain
#
# Installs Nginx, PHP 8.3, MySQL, Composer and Node.js, creates the database,
# clones the repo to /var/www/strategy_planner, writes .env, configures
# Nginx, and runs the first ./deploy.sh. Safe to re-run: existing pieces
# (database, .env, clone) are kept.
#
set -Eeuo pipefail

REPO_URL="${REPO_URL:-https://github.com/IsaacNyamari/strategy_planner.git}"
APP_DIR="${APP_DIR:-/var/www/strategy_planner}"
BRANCH="${BRANCH:-main}"
PHP_VERSION="8.3"
DB_NAME="strategy_planner"
DB_USER="strategy_planner"
SERVER_NAME="${1:-$(hostname -I | awk '{print $1}')}"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
[ "$(id -u)" = 0 ] || { echo "Run as root." >&2; exit 1; }
export DEBIAN_FRONTEND=noninteractive

step "Base packages"
apt-get update -q
apt-get install -y -q ca-certificates curl git unzip gnupg lsb-release software-properties-common nginx mysql-server openssl

step "PHP $PHP_VERSION"
if ! apt-cache show "php$PHP_VERSION-fpm" >/dev/null 2>&1; then
    . /etc/os-release
    if [ "$ID" = "ubuntu" ]; then
        add-apt-repository -y ppa:ondrej/php
    else
        curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
        echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $VERSION_CODENAME main" > /etc/apt/sources.list.d/sury-php.list
    fi
    apt-get update -q
fi
apt-get install -y -q "php$PHP_VERSION-fpm" "php$PHP_VERSION-cli" "php$PHP_VERSION-mysql" "php$PHP_VERSION-mbstring" \
    "php$PHP_VERSION-xml" "php$PHP_VERSION-zip" "php$PHP_VERSION-gd" "php$PHP_VERSION-curl" "php$PHP_VERSION-intl" \
    "php$PHP_VERSION-bcmath" "php$PHP_VERSION-sqlite3"

step "Composer"
if ! command -v composer >/dev/null; then
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    actual="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [ "$expected" = "$actual" ] || { echo "Composer installer checksum mismatch" >&2; exit 1; }
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

step "Node.js"
node_major="$(node -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/' || echo 0)"
if [ "${node_major:-0}" -lt 20 ]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y -q nodejs
fi

step "Database"
systemctl enable --now mysql >/dev/null
if [ ! -f /root/.strategy_planner_db ]; then
    DB_PASS="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24)"
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
    umask 077; echo "$DB_PASS" > /root/.strategy_planner_db
fi
DB_PASS="$(cat /root/.strategy_planner_db)"

step "Code"
if [ ! -d "$APP_DIR/.git" ]; then
    mkdir -p "$(dirname "$APP_DIR")"
    git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"
chmod +x deploy.sh

step "Environment (.env)"
set_env() { # set_env KEY VALUE -> replace or append in .env
    local key="$1" value="$2"
    if grep -qE "^#?\s*${key}=" .env; then
        sed -i -E "s|^#?\s*${key}=.*|${key}=${value}|" .env
    else
        echo "${key}=${value}" >> .env
    fi
}
if [ ! -f .env ]; then
    cp .env.example .env
    scheme="http"
    set_env APP_NAME '"Strategy Planner"'
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "$scheme://$SERVER_NAME"
    set_env LOG_LEVEL warning
    set_env DB_CONNECTION mysql
    set_env DB_HOST 127.0.0.1
    set_env DB_PORT 3306
    set_env DB_DATABASE "$DB_NAME"
    set_env DB_USERNAME "$DB_USER"
    set_env DB_PASSWORD "$DB_PASS"
    chmod 640 .env
    chown root:www-data .env
fi

composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
grep -qE '^APP_KEY=base64:' .env || php artisan key:generate --force

step "Nginx"
PHP_SOCK="/run/php/php$PHP_VERSION-fpm.sock"
cat > /etc/nginx/sites-available/strategy_planner <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $SERVER_NAME;
    root $APP_DIR/public;
    index index.php;
    charset utf-8;
    client_max_body_size 20M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    gzip on;
    gzip_comp_level 6;
    gzip_min_length 1024;
    gzip_types text/css application/javascript application/json image/svg+xml text/plain;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Versioned build assets can be cached for a year.
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:$PHP_SOCK;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_read_timeout 120s; # AI requests can take a while
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/strategy_planner /etc/nginx/sites-enabled/strategy_planner
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

# Allow web traffic if the firewall is on (never enables it, to avoid lock-outs).
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
    ufw allow 'Nginx Full' >/dev/null || true
fi

step "Scheduler (cron)"
echo "* * * * * www-data cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/strategy_planner

step "First deploy"
./deploy.sh "$BRANCH"

cat <<DONE

Setup complete.
  Site:      http://$SERVER_NAME
  App dir:   $APP_DIR
  DB pass:   /root/.strategy_planner_db (root-only)

Next steps:
  - Add your Gemini key to $APP_DIR/.env (GEMINI_API_KEY=...) then run: php artisan config:cache
  - Using a domain? Point its DNS here, then for HTTPS: apt install certbot python3-certbot-nginx && certbot --nginx -d $SERVER_NAME
  - Future updates: cd $APP_DIR && ./deploy.sh
DONE
