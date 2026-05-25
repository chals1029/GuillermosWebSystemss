#!/usr/bin/env bash
set -e
SECRET=$(grep '^WEBHOOK_SECRET=' /var/www/guillermoscafe/.env | cut -d= -f2-)
echo "--- HEAD before ---"
git -C /var/www/guillermoscafe log -1 --oneline
echo "--- send signed push event ---"
PAYLOAD='{"ref":"refs/heads/main","head_commit":{"id":"test"}}'
SIG="sha256=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.*= //')"
curl -s -o /tmp/wh.out -w 'HTTP %{http_code}\n' \
  -X POST \
  -H 'Content-Type: application/json' \
  -H 'X-GitHub-Event: push' \
  -H "X-Hub-Signature-256: $SIG" \
  --data "$PAYLOAD" \
  http://127.0.0.1/webhook.php
cat /tmp/wh.out; echo
echo "--- wait for deploy ---"
sleep 5
echo "--- last 25 lines of deploy log ---"
tail -25 /var/log/guillermoscafe-deploy.log
echo "--- HEAD after ---"
git -C /var/www/guillermoscafe log -1 --oneline
