<?php
require_once __DIR__ . '/vendor/autoload.php';

googleOAuthHandler();

function googleOAuthHandler(): void
{
    $client = buildClient();

    if (!isset($_GET['code'])) {
        $authUrl = $client->createAuthUrl();
        echo '<h2>Authorize Google Account</h2>';
        echo '<p><a href="' . htmlspecialchars($authUrl) . '">Click here to connect your Gmail account</a></p>';
        return;
    }

    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (isset($token['error'])) {
        http_response_code(400);
        echo '<h2>Authorization failed</h2>';
        echo '<p>' . htmlspecialchars($token['error_description'] ?? $token['error']) . '</p>';
        return;
    }

    if (!isset($token['refresh_token'])) {
        $existingToken = loadToken();
        if (isset($existingToken['refresh_token'])) {
            $token['refresh_token'] = $existingToken['refresh_token'];
        }
    }

    saveToken($token);

    echo '<h2>Authorization successful</h2>';
    echo '<p>Tokens saved. You may close this window and retry registration.</p>';
}

function buildClient(): Google\Client
{
    $client = new Google\Client();
    $client->setAuthConfig(clientSecretPath());
    $client->setRedirectUri('https://www.guillermoscafe.shop/oauth2callback.php');
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setScopes([Google\Service\Gmail::GMAIL_SEND]);
    return $client;
}

function clientSecretPath(): string
{
    $matches = glob(__DIR__ . '/client_secret_*.json');
    if (!$matches) {
        throw new RuntimeException('Client secret JSON not found.');
    }
    return $matches[0];
}

function tokenPath(): string
{
    return __DIR__ . '/google_token.json';
}

function saveToken(array $token): void
{
    $token['created'] = time();
    file_put_contents(tokenPath(), json_encode($token));
}

function loadToken(): array
{
    $path = tokenPath();
    if (!file_exists($path)) {
        return [];
    }

    $content = json_decode((string)file_get_contents($path), true);
    return is_array($content) ? $content : [];
}
