<?php
require_once __DIR__ . '/../Controllers/PasswordPolicy.php';

header('Content-Type: application/json; charset=utf-8');

$password = '';
if (isset($_POST['password']) && is_string($_POST['password'])) {
    $password = $_POST['password'];
} elseif (isset($_GET['password']) && is_string($_GET['password'])) {
    $password = $_GET['password'];
}

$checks = passwordPolicyChecks($password);

$response = [
    'passwordLength' => strlen($password),
    'checks' => $checks,
    'isStrong' => passwordPolicyIsStrong($password),
    'policyMessage' => passwordPolicyErrorMessage(),
    'example' => '/TestingBackend/test.php?password=YourPass123!',
];

echo json_encode($response, JSON_PRETTY_PRINT);
