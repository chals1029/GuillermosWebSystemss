<?php
require __DIR__ . '/../Config.php';

$username = 'Chalzz';
$newHash = password_hash('admin223', PASSWORD_DEFAULT);

$stmt = $conn->prepare('UPDATE users SET password = ? WHERE username = ?');
if (!$stmt) {
    fwrite(STDERR, "Prepare failed: " . $conn->error . PHP_EOL);
    exit(1);
}

$stmt->bind_param('ss', $newHash, $username);
if (!$stmt->execute()) {
    fwrite(STDERR, "Execute failed: " . $stmt->error . PHP_EOL);
    $stmt->close();
    exit(1);
}

$stmt->close();

echo "Password updated for {$username}." . PHP_EOL;
