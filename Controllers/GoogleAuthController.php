<?php
session_start();

use Google\Client;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Models/User.php';

try {
    $userModel = new User($conn);
    $client = buildGoogleClient();

    if (!isset($_GET['code'])) {
        $authUrl = $client->createAuthUrl();
        header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
        exit;
    }

    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (isset($token['error'])) {
        throw new RuntimeException($token['error_description'] ?? $token['error']);
    }

    $client->setAccessToken($token);

    $oauth2ServiceClass = '\\Google\\Service\\Oauth2';
    $oauth2 = new $oauth2ServiceClass($client);
    $googleUser = $oauth2->userinfo->get();

    $email = $googleUser->email ?? '';
    if ($email === '') {
        throw new RuntimeException('Unable to retrieve email address from Google.');
    }

    $rawName = $googleUser->name ?: trim(($googleUser->givenName ?? '') . ' ' . ($googleUser->familyName ?? ''));
    $name = trim((string)$rawName);
    $givenName = trim((string)($googleUser->givenName ?? ''));
    $familyName = trim((string)($googleUser->familyName ?? ''));
    $usernameSuggestion = $givenName !== ''
        ? $givenName
        : ($email ? strstr($email, '@', true) : '');
    $usernameSuggestion = trim((string)$usernameSuggestion);

    $user = $userModel->findByEmail($email);

    if ($user) {
        // Existing account linked by email: sign in immediately and continue to role destination.
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['user_role'];
        $_SESSION['user'] = [
            'user_id' => $user['user_id'],
            'Username' => $user['username'],
            'Name' => $user['name'],
            'Email' => $user['email'],
            'Phonenumber' => $user['phonenumber'],
            'user_role' => $user['user_role'],
        ];

        unset($_SESSION['google_auth_hint']);

        $target = appBasePath() . roleDestinationPath((string)($user['user_role'] ?? ''));
        header('Location: ' . $target);
        exit;
    }

    $hintData = [
        'email' => $email,
        'name' => $name !== '' ? $name : null,
        'givenName' => $givenName !== '' ? $givenName : null,
        'familyName' => $familyName !== '' ? $familyName : null,
        'suggestedUsername' => $usernameSuggestion !== '' ? $usernameSuggestion : null,
    ];

    $hintData['status'] = 'new';
    $hintData['message'] = 'We saved your Google email. Complete the details to finish signing up.';

    $_SESSION['google_auth_hint'] = array_filter(
        $hintData,
        static fn($value) => $value !== null && $value !== ''
    );

    $query = [
        'google' => $hintData['status'] ?? 'existing',
    ];

    if (!empty($hintData['email'])) {
        $query['gh_email'] = (string)$hintData['email'];
    }
    if (!empty($hintData['name'])) {
        $query['gh_name'] = (string)$hintData['name'];
    }
    if (!empty($hintData['suggestedUsername'])) {
        $query['gh_username'] = (string)$hintData['suggestedUsername'];
    }
    if (!empty($hintData['message'])) {
        $query['gh_message'] = (string)$hintData['message'];
    }

    $target = appBasePath() . '/Views/landing/index.php?' . http_build_query($query);
    header('Location: ' . $target);
    exit;
} catch (Throwable $e) {
    redirectWithError($e->getMessage());
}

function buildGoogleClient(): Client
{
    $client = new Client();
    $client->setAuthConfig(clientSecretPath());
    $client->setRedirectUri(appBaseUrl() . '/Controllers/GoogleAuthController.php');
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setScopes([
        'email',
        'profile',
    ]);

    return $client;
}

function clientSecretPath(): string
{
    $matches = glob(__DIR__ . '/../client_secret_*.json');
    if (!$matches) {
        throw new RuntimeException('Client secret JSON not found.');
    }

    return $matches[0];
}

function redirectWithError(string $message): void
{
    $target = appBasePath() . '/Views/landing/index.php?error=' . urlencode($message);
    header('Location: ' . $target);
    exit;
}

function appBasePath(): string
{
    if (defined('APP_BASE_PATH') && APP_BASE_PATH !== '') {
        return APP_BASE_PATH;
    }
    $scriptDir = dirname($_SERVER['PHP_SELF'] ?? '') ?: '';
    $base = preg_replace('#/Controllers/?$#', '', $scriptDir);
    if ($base === null || $base === false) {
        $base = '';
    }
    $base = rtrim($base, '/');
    return $base === '' ? '' : $base;
}

function appBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . appBasePath();
}

function roleDestinationPath(string $role): string
{
    switch (strtolower($role)) {
        case 'customer':
            return '/Views/customer_dashboard/Customer.php';
        case 'staff':
            return '/Views/staff_dashboard/staff.php';
        case 'owner':
        case 'admin':
            return '/Views/owner_dashboard/Owner.php';
        default:
            return '/';
    }
}
