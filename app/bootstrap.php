<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

function env_get(string $name): string|false
{
    if (function_exists('getenv')) {
        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }
    }

    if (array_key_exists($name, $_ENV) && is_scalar($_ENV[$name])) {
        return (string) $_ENV[$name];
    }

    if (array_key_exists($name, $_SERVER) && is_scalar($_SERVER[$name])) {
        return (string) $_SERVER[$name];
    }

    return false;
}

$envFile = $basePath . '/.env';
if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            if ($name === '' || env_get($name) !== false) {
                continue;
            }

            if (function_exists('putenv')) {
                putenv($name . '=' . $value);
            }
            if (function_exists('apache_setenv')) {
                apache_setenv($name, $value);
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

require_once $basePath . '/app/Database.php';
require_once $basePath . '/app/Recurrence.php';
require_once $basePath . '/app/helpers.php';
require_once $basePath . '/app/Auth.php';
require_once $basePath . '/app/Mailer.php';
require_once $basePath . '/app/Invites.php';
require_once $basePath . '/app/EmailConfirm.php';
require_once $basePath . '/app/AccountDelete.php';
require_once $basePath . '/app/StatementExport.php';

$config = [
    'app' => [
        'name' => env_get('APP_NAME') ?: 'HomeLedger',
        'url' => env_get('APP_URL') ?: '',
        'currency' => env_get('APP_CURRENCY') ?: 'GBP',
        'currency_symbol' => env_get('APP_CURRENCY_SYMBOL') ?: '£',
        'timezone' => env_get('APP_TIMEZONE') ?: 'Europe/London',
    ],
    'database' => [
        'host' => env_get('DB_HOST') ?: '127.0.0.1',
        'port' => env_get('DB_PORT') ?: '3306',
        'database' => env_get('DB_DATABASE') ?: 'homeledger',
        'username' => env_get('DB_USERNAME') ?: 'homeledger',
        'password' => env_get('DB_PASSWORD') ?: 'homeledger',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'host' => env_get('MAIL_HOST') ?: '',
        'port' => env_get('MAIL_PORT') ?: '587',
        'username' => env_get('MAIL_USERNAME') ?: '',
        'password' => env_get('MAIL_PASSWORD') ?: '',
        'from' => env_get('MAIL_FROM') ?: '',
        'from_name' => env_get('MAIL_FROM_NAME') ?: 'HomeLedger',
        'encryption' => env_get('MAIL_ENCRYPTION') ?: 'tls',
    ],
    'brevo' => [
        'api_key' => env_get('BREVO_API_KEY') ?: '',
    ],
];

date_default_timezone_set((string) $config['app']['timezone']);

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}
