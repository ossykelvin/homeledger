<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

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
            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            putenv($name . '=' . $value);
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
require_once $basePath . '/app/StatementExport.php';

$config = [
    'app' => [
        'name' => getenv('APP_NAME') ?: 'HomeLedger',
        'url' => getenv('APP_URL') ?: '',
        'currency' => getenv('APP_CURRENCY') ?: 'GBP',
        'currency_symbol' => getenv('APP_CURRENCY_SYMBOL') ?: '£',
        'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/London',
    ],
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_DATABASE') ?: 'homeledger',
        'username' => getenv('DB_USERNAME') ?: 'homeledger',
        'password' => getenv('DB_PASSWORD') ?: 'homeledger',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'host' => getenv('MAIL_HOST') ?: '',
        'port' => getenv('MAIL_PORT') ?: '587',
        'username' => getenv('MAIL_USERNAME') ?: '',
        'password' => getenv('MAIL_PASSWORD') ?: '',
        'from' => getenv('MAIL_FROM') ?: '',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'HomeLedger',
        'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
    ],
    'brevo' => [
        'api_key' => getenv('BREVO_API_KEY') ?: '',
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
