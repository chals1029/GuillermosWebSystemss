<?php
if (!function_exists('load_project_env')) {
    function load_project_env(string $envFilePath): void
    {
        static $loadedFiles = [];

        if (isset($loadedFiles[$envFilePath])) {
            return;
        }
        $loadedFiles[$envFilePath] = true;

        if (!is_file($envFilePath) || !is_readable($envFilePath)) {
            return;
        }

        $vars = parse_ini_file($envFilePath, false, INI_SCANNER_RAW);
        if (!is_array($vars)) {
            return;
        }

        foreach ($vars as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $value = (string)$value;
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

load_project_env(__DIR__ . '/.env');

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$dbname = getenv('DB_DATABASE') ?: 'u435394025_guillermos_db';


$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {

    error_log('Database connection failed: (' . $conn->connect_errno . ') ' . $conn->connect_error);
    http_response_code(500);
    exit('Database connection failed.');
}

date_default_timezone_set('Asia/Manila');

if (!defined('APP_BASE_PATH')) {
    // Override this constant in production if the project lives in a subdirectory
    // Default is empty (root). Set APP_BASE_PATH env var to '/subfolder' if deployed under a subpath.
    $envPath = getenv('APP_BASE_PATH') ?: '';
    if ($envPath === '') {
        define('APP_BASE_PATH', '');
    } else {
        // ensure path starts with a single slash, and no trailing slash
        $envPath = '/' . trim($envPath, '/');
        define('APP_BASE_PATH', rtrim($envPath, '/'));
    }
}

?>
