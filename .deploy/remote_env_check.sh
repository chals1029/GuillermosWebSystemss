#!/usr/bin/env bash
set -e
cd /var/www/guillermoscafe
ASKPASS=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' 'Drake24Charles' > "$ASKPASS"; chmod 700 "$ASKPASS"
export SUDO_ASKPASS="$ASKPASS"

cat > /tmp/envcheck.php <<'PHP'
<?php
require __DIR__ . '/Config.php';
$keys = ['GOOGLE_API_KEY','GEMINI_API_KEY','MAIL_USERNAME','MAIL_HOST','DB_USERNAME','DB_DATABASE','APP_ENV','APP_URL','WEBHOOK_SECRET','AUTH_DDOS_MAX_REQUESTS'];
foreach ($keys as $k) {
    $v = getenv($k);
    if ($v === false) { echo "$k = [missing]\n"; continue; }
    if (preg_match('/PASSWORD|SECRET|API_KEY|TOKEN/i', $k)) {
        echo "$k = [len=" . strlen($v) . " hash=" . substr(sha1($v), 0, 8) . "]\n";
    } else {
        echo "$k = $v\n";
    }
}
echo "client_secret files: " . count(glob(__DIR__ . '/client_secret_*.json')) . "\n";

// Test DB connectivity using whatever Config.php exposed
if (isset($conn) && $conn instanceof mysqli) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM users");
    if ($r) { $row = $r->fetch_assoc(); echo "users.count=" . $row['c'] . "\n"; }
    else { echo "DB query failed: " . $conn->error . "\n"; }
} else {
    echo "no \$conn defined by Config.php\n";
}
PHP

# Place envcheck.php inside the app dir (where Config.php lives) and run as www-data
sudo -A install -o BeaBunda -g www-data -m 0640 /tmp/envcheck.php /var/www/guillermoscafe/envcheck.php
sudo -A -u www-data php /var/www/guillermoscafe/envcheck.php
sudo -A rm -f /var/www/guillermoscafe/envcheck.php /tmp/envcheck.php
rm -f "$ASKPASS"
