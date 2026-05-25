#!/usr/bin/env bash
# Install Let's Encrypt cert for guillermoscafe.shop and configure HTTPS.
set -euo pipefail

APP_DOMAIN='guillermoscafe.shop'
EMAIL="${ADMIN_EMAIL:-admin@guillermoscafe.shop}"

# SUDO_ASKPASS, no stdin collisions
_DEPLOY_DIR=$(dirname "${BASH_SOURCE[0]:-$0}"); . "$_DEPLOY_DIR/_loadsecrets.sh"; ASKPASS_FILE=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' "$VPS_PASSWORD" > "$ASKPASS_FILE"; chmod 700 "$ASKPASS_FILE"
export SUDO_ASKPASS="$ASKPASS_FILE"
trap 'rm -f "$ASKPASS_FILE"' EXIT
S() { sudo -A "$@"; }

echo "==> Install certbot"
S DEBIAN_FRONTEND=noninteractive apt-get update -y
S DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-apache

echo "==> Verify domain resolves to this server"
PUBLIC_IP=$(curl -s -4 ifconfig.io)
RESOLVED=$(getent hosts "$APP_DOMAIN" | awk '{print $1; exit}')
echo "Public IP: $PUBLIC_IP"
echo "DNS for $APP_DOMAIN: $RESOLVED"
if [ "$PUBLIC_IP" != "$RESOLVED" ]; then
  echo "WARNING: DNS does not match public IP. Continuing anyway, certbot may fail."
fi

echo "==> Run certbot --apache (also handles redirect to HTTPS)"
# Try with both apex and www; fall back to apex-only if www isn't pointed.
if S certbot --apache -d "$APP_DOMAIN" -d "www.$APP_DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect; then
  echo "Cert issued for both apex and www"
else
  echo "www failed, trying apex-only..."
  S certbot --apache -d "$APP_DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect
fi

echo "==> Test renewal"
S certbot renew --dry-run

echo "==> Reload apache"
S systemctl reload apache2

echo "==> Verify"
curl -sI "https://${APP_DOMAIN}/" | head -5 || true
curl -sI "http://${APP_DOMAIN}/" | head -5 || true
