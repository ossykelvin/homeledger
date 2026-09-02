<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/actions.php';

$setupError = null;
try {
    db();
    materialise_due_recurring_entries();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $setupError = $exception;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($setupError) {
        http_response_code(503);
        exit('The database is not ready. Complete the setup steps in README.md and refresh this page.');
    }
    handle_post_action();
}

$allowedPages = ['dashboard', 'transactions', 'recurring'];
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'dashboard';
if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'dashboard';
}

$titles = [
    'dashboard' => 'Overview',
    'transactions' => 'Transactions',
    'recurring' => 'Recurring entries',
];
$pageTitle = $titles[$page];
$flash = pull_flash();

require dirname(__DIR__) . '/templates/layout.php';
