<?php
// Make absolutely sure no cached page is served after logout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session variables.
$_SESSION = [];

// Expire the session cookie on the client side so the old PHPSESSID
// cannot be replayed against a protected page.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path']     ?? '/',
            'domain'   => $params['domain']   ?? '',
            'secure'   => $params['secure']   ?? (!empty($_SERVER['HTTPS'])),
            'httponly' => $params['httponly'] ?? true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

// Destroy the session on the server.
session_unset();
session_destroy();

// Compute base path so logout works whether the app lives at / or /subdir.
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');
if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}

header('Location: ' . $basePath . '/Views/landing/index.php');
exit;
