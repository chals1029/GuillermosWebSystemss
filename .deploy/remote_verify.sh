#!/usr/bin/env bash
set -e
DB='u435394025_guillermos_db'

# Use SUDO_ASKPASS for non-interactive sudo
ASKPASS=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' "Drake24Charles" > "$ASKPASS"; chmod 700 "$ASKPASS"
export SUDO_ASKPASS="$ASKPASS"

echo '--- table count ---'
sudo -A mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB}'"
echo '--- first 20 tables ---'
sudo -A mysql -uroot -N -e "SHOW TABLES IN ${DB}" | head -20
echo '--- apache status ---'
systemctl is-active apache2 || true
echo '--- sites enabled ---'
ls /etc/apache2/sites-enabled/
echo '--- vhost has guillermoscafe ---'
grep -E 'ServerName|DocumentRoot' /etc/apache2/sites-available/guillermoscafe.conf
echo '--- HTTP / response (Host: guillermoscafe.shop) ---'
curl -sI -H 'Host: guillermoscafe.shop' http://127.0.0.1/ | head -5
echo '--- webhook GET (should 401 missing-sig) ---'
curl -s -o /tmp/wh.out -w 'HTTP %{http_code}\n' -H 'Host: guillermoscafe.shop' http://127.0.0.1/webhook.php
cat /tmp/wh.out; echo
echo '--- webhook ping with valid signature ---'
SECRET=$(grep '^WEBHOOK_SECRET=' /var/www/guillermoscafe/.env | cut -d= -f2-)
PAYLOAD='{"zen":"Practice empathy."}'
SIG="sha256=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.*= //')"
curl -s -o /tmp/wh.out -w 'HTTP %{http_code}\n' \
  -H 'Host: guillermoscafe.shop' \
  -H 'Content-Type: application/json' \
  -H 'X-GitHub-Event: ping' \
  -H "X-Hub-Signature-256: $SIG" \
  --data-binary "$PAYLOAD" \
  http://127.0.0.1/webhook.php
cat /tmp/wh.out; echo
echo '--- deploy script perms ---'
ls -la /var/www/guillermoscafe/.deploy/deploy.sh
echo '--- sudoers entry ---'
sudo -A cat /etc/sudoers.d/guillermoscafe-deploy
echo '--- public IP and external check ---'
curl -s -4 ifconfig.io || echo "(could not fetch external IP)"

rm -f "$ASKPASS"
