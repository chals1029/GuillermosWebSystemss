#!/usr/bin/env bash
# Provision Ubuntu 24.04 VPS for the GuillermosWebSystemss PHP application.
# Idempotent: safe to re-run.

set -euo pipefail

_DEPLOY_DIR=$(dirname "${BASH_SOURCE[0]:-$0}"); . "$_DEPLOY_DIR/_loadsecrets.sh"
SUDO_PASS="$VPS_PASSWORD"
APP_USER="BeaBunda"
APP_DOMAIN="${APP_DOMAIN:-guillermoscafe.shop}"
APP_DIR="${APP_DIR:-/var/www/guillermoscafe}"
REPO_URL="${REPO_URL:-https://github.com/chals1029/GuillermosWebSystemss.git}"
DB_NAME="${DB_NAME:-u435394025_guillermos_db}"
DB_USER="${DB_USER:-guillermos}"
DB_PASS="${DB_PASS:-}"             # set by caller (random)
WEBHOOK_SECRET="${WEBHOOK_SECRET:-}" # set by caller (random)

# Use SUDO_ASKPASS so we don't fight stdin with heredocs / pipes.
ASKPASS_FILE="$(mktemp)"
trap 'rm -f "$ASKPASS_FILE"' EXIT
cat > "$ASKPASS_FILE" <<EOF
#!/usr/bin/env bash
echo "$SUDO_PASS"
EOF
chmod 700 "$ASKPASS_FILE"
export SUDO_ASKPASS="$ASKPASS_FILE"

S() { sudo -A "$@"; }

echo "==> [1/9] apt update + base packages"
S DEBIAN_FRONTEND=noninteractive apt-get update -y
S DEBIAN_FRONTEND=noninteractive apt-get install -y \
  apache2 mysql-server php php-cli php-mysql php-curl php-mbstring php-xml \
  php-zip php-gd php-bcmath php-intl php-fpm libapache2-mod-php \
  unzip git curl ca-certificates ufw acl jq

echo "==> [2/9] composer"
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_SIG=$(curl -fsSL https://composer.github.io/installer.sig)
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL_SIG=$(php -r "echo hash_file('sha384','/tmp/composer-setup.php');")
  [ "$EXPECTED_SIG" = "$ACTUAL_SIG" ] || { echo "Composer installer signature mismatch"; exit 1; }
  S php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
composer --version || true

echo "==> [3/9] firewall"
S ufw --force reset >/dev/null
S ufw default deny incoming
S ufw default allow outgoing
S ufw allow OpenSSH
S ufw allow 'Apache Full'
S ufw --force enable
S ufw status

echo "==> [4/9] app directory + clone"
S mkdir -p "$APP_DIR"
S chown -R "$APP_USER:www-data" "$APP_DIR"
S chmod -R 2775 "$APP_DIR"
if [ ! -d "$APP_DIR/.git" ]; then
  git clone "$REPO_URL" "$APP_DIR"
else
  git -C "$APP_DIR" remote set-url origin "$REPO_URL"
  git -C "$APP_DIR" fetch --all --prune
  # Use default branch from origin
  DEF_BRANCH=$(git -C "$APP_DIR" remote show origin | awk '/HEAD branch/ {print $NF}')
  git -C "$APP_DIR" checkout "$DEF_BRANCH"
  git -C "$APP_DIR" reset --hard "origin/$DEF_BRANCH"
fi
# Permissions: BeaBunda owner, www-data group, group writable so Apache can serve and git can pull
S chown -R "$APP_USER:www-data" "$APP_DIR"
S find "$APP_DIR" -type d -exec chmod 2775 {} +
S find "$APP_DIR" -type f -exec chmod 0664 {} +
S setfacl -R -m u:www-data:rX "$APP_DIR" || true
S setfacl -dR -m u:www-data:rX "$APP_DIR" || true

echo "==> [5/9] composer install (if composer.json present)"
if [ -f "$APP_DIR/composer.json" ]; then
  cd "$APP_DIR"
  composer install --no-interaction --no-dev --prefer-dist || composer install --no-interaction --prefer-dist
fi

echo "==> [6/9] mysql: create db + user + import"
S systemctl enable --now mysql
# Bootstrap DB and user
S mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# Import dump if present and DB is empty
if [ -f "/tmp/guillermos_dump.sql" ]; then
  TABLE_COUNT=$(S mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';")
  if [ "${TABLE_COUNT:-0}" -eq 0 ]; then
    echo "Importing dump..."
    S mysql -uroot "${DB_NAME}" < /tmp/guillermos_dump.sql
  else
    echo "DB already has $TABLE_COUNT tables; skipping import. Re-run with FORCE_IMPORT=1 to overwrite."
    if [ "${FORCE_IMPORT:-0}" = "1" ]; then
      S mysql -uroot -e "DROP DATABASE \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
      S mysql -uroot "${DB_NAME}" < /tmp/guillermos_dump.sql
    fi
  fi
fi

echo "==> [7/9] write app .env"
ENV_FILE="$APP_DIR/.env"
if [ ! -f "$ENV_FILE" ] || [ "${FORCE_ENV:-0}" = "1" ]; then
  S tee "$ENV_FILE" >/dev/null <<EOF
APP_ENV=production
APP_URL=https://${APP_DOMAIN}
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}
WEBHOOK_SECRET=${WEBHOOK_SECRET}
EOF
  S chown "$APP_USER:www-data" "$ENV_FILE"
  S chmod 640 "$ENV_FILE"
fi

echo "==> [8/9] apache vhost + modules"
S a2enmod rewrite headers ssl >/dev/null
VHOST=/etc/apache2/sites-available/guillermoscafe.conf
S tee "$VHOST" >/dev/null <<APACHECONF
<VirtualHost *:80>
    ServerName ${APP_DOMAIN}
    ServerAlias www.${APP_DOMAIN}
    DocumentRoot ${APP_DIR}

    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Block dotfiles, .env, vendor, .git, sql dumps
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
    <DirectoryMatch "(^|/)(\.git|vendor|sql|storage_rate_limits|tests|TestingBackend|\.deploy)(/|$)">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "\.(sql|env|md|lock|json)$">
        Require all denied
    </FilesMatch>
    # Allow composer.json reading is blocked above; that's fine

    ErrorLog \${APACHE_LOG_DIR}/guillermoscafe-error.log
    CustomLog \${APACHE_LOG_DIR}/guillermoscafe-access.log combined
</VirtualHost>
APACHECONF
S a2dissite 000-default.conf >/dev/null 2>&1 || true
S a2ensite guillermoscafe.conf >/dev/null
S apache2ctl configtest
S systemctl reload apache2

echo "==> [9/9] webhook deploy script + sudoers"
DEPLOY_SH="$APP_DIR/.deploy/deploy.sh"
S mkdir -p "$APP_DIR/.deploy"
S tee "$DEPLOY_SH" >/dev/null <<'DEPLOY'
#!/usr/bin/env bash
set -euo pipefail
APP_DIR="/var/www/guillermoscafe"
LOG="/var/log/guillermoscafe-deploy.log"
{
  echo "===== $(date -Is) deploy start ====="
  cd "$APP_DIR"
  # Resolve default branch dynamically
  BRANCH=$(git remote show origin 2>/dev/null | awk '/HEAD branch/ {print $NF}')
  BRANCH=${BRANCH:-main}
  git fetch --all --prune
  git reset --hard "origin/$BRANCH"
  if [ -f composer.json ]; then
    composer install --no-interaction --no-dev --prefer-dist || true
  fi
  # Re-fix permissions for any new files brought in by the pull
  chown -R BeaBunda:www-data "$APP_DIR"
  find "$APP_DIR" -type d -exec chmod 2775 {} +
  find "$APP_DIR" -type f -exec chmod 0664 {} +
  systemctl reload apache2 || true
  echo "===== $(date -Is) deploy ok ====="
} >> "$LOG" 2>&1
DEPLOY
S chmod 755 "$DEPLOY_SH"
S chown "$APP_USER:www-data" "$DEPLOY_SH"
S touch /var/log/guillermoscafe-deploy.log
S chown www-data:www-data /var/log/guillermoscafe-deploy.log
S chmod 664 /var/log/guillermoscafe-deploy.log

# Allow www-data to run only this deploy script as root, no password
SUDOERS=/etc/sudoers.d/guillermoscafe-deploy
S tee "$SUDOERS" >/dev/null <<EOF
www-data ALL=(root) NOPASSWD: ${DEPLOY_SH}
EOF
S chmod 440 "$SUDOERS"
S visudo -cf "$SUDOERS"

echo "==> done"
