<?php
// Quick test endpoint to trigger PHPMailer send and print result + log path
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/EmailApiController.php';

header('Content-Type: text/plain; charset=utf-8');

$to = $_GET['to'] ?? '';
$name = $_GET['name'] ?? 'Test User';
$code = $_GET['code'] ?? str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "Usage: ?to=you@domain.tld[&name=Name][&code=123456]\n";
    exit;
}

echo "Sending test verification to: $to\n";
try {
    $result = EmailApiController::sendVerificationEmail($to, $name, $code);
    if ($result === true) {
        echo "Send result: SUCCESS\n";
    } else {
        echo "Send result: FAILURE\n";
        echo "Message: " . (string)$result . "\n";
    }
} catch (Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

// Show where logs are written
$tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'guillermos_logs' . DIRECTORY_SEPARATOR . 'email.log';
echo "\nLog file (tail): $tmp\n";
if (is_readable($tmp)) {
    echo "\n=== last 200 lines ===\n";
    $lines = array_slice(file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -200);
    echo implode("\n", $lines);
} else {
    echo "\nLog file not found or not readable.\n";
}

exit;
