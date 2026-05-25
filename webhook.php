<?php
/**
 * GitHub webhook receiver.
 * Verifies HMAC-SHA256 signature using WEBHOOK_SECRET from .env, then runs the
 * deploy script via sudo. Drop this file at the document root and configure a
 * GitHub webhook with content type application/json pointing at /webhook.php.
 */
declare(strict_types=1);

http_response_code(200);
header('Content-Type: application/json');

$logFile = '/var/log/guillermoscafe-deploy.log';
$logLine = function (string $msg) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('c') . '] webhook: ' . $msg . "\n", FILE_APPEND);
};

// Load secret from .env (simple parser, only for WEBHOOK_SECRET)
$secret = '';
$envPath = __DIR__ . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, 'WEBHOOK_SECRET=') === 0) {
            $secret = trim(substr($line, strlen('WEBHOOK_SECRET=')), "\"' \t");
            break;
        }
    }
}

if ($secret === '') {
    http_response_code(500);
    $logLine('missing WEBHOOK_SECRET');
    echo json_encode(['ok' => false, 'error' => 'server not configured']);
    exit;
}

$payload = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

if ($sigHeader === '') {
    http_response_code(401);
    $logLine('missing signature header');
    echo json_encode(['ok' => false, 'error' => 'missing signature']);
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $sigHeader)) {
    http_response_code(401);
    $logLine('invalid signature');
    echo json_encode(['ok' => false, 'error' => 'invalid signature']);
    exit;
}

if ($event === 'ping') {
    $logLine('ping ok');
    echo json_encode(['ok' => true, 'pong' => true]);
    exit;
}

if ($event !== 'push') {
    $logLine("ignored event: $event");
    echo json_encode(['ok' => true, 'ignored' => $event]);
    exit;
}

$body = json_decode($payload, true);
$ref = is_array($body) ? ($body['ref'] ?? '') : '';

// Only deploy on default branch pushes (main or master)
if ($ref !== 'refs/heads/main' && $ref !== 'refs/heads/master') {
    $logLine("ignored ref: $ref");
    echo json_encode(['ok' => true, 'ignored_ref' => $ref]);
    exit;
}

$deployScript = '/var/www/guillermoscafe/.deploy/deploy.sh';
$cmd = 'sudo -n ' . escapeshellarg($deployScript) . ' > /dev/null 2>&1 &';
exec($cmd, $out, $rc);
$logLine("deploy triggered for $ref (rc=$rc)");

echo json_encode(['ok' => true, 'deployed_ref' => $ref]);
