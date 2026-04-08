<?php

if (!function_exists('passwordPolicyChecks')) {
    function passwordPolicyChecks(string $password): array
    {
        return [
            'minLength' => strlen($password) >= 8,
            'hasUpper' => preg_match('/[A-Z]/', $password) === 1,
            'hasLower' => preg_match('/[a-z]/', $password) === 1,
            'hasNumber' => preg_match('/\d/', $password) === 1,
            'hasSpecial' => preg_match('/[^A-Za-z0-9]/', $password) === 1,
        ];
    }
}

if (!function_exists('passwordPolicyIsStrong')) {
    function passwordPolicyIsStrong(string $password): bool
    {
        $checks = passwordPolicyChecks($password);
        foreach ($checks as $passed) {
            if ($passed !== true) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('passwordPolicyErrorMessage')) {
    function passwordPolicyErrorMessage(): string
    {
        return 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.';
    }
}
