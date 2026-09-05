<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/actions.php';

header('Cache-Control: no-store, private');

$setupError = null;
try {
    db();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $setupError = $exception;
}

if (!$setupError) {
    try {
        db()->query('SELECT 1 FROM households LIMIT 1');
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        $setupError = $exception;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($setupError) {
        http_response_code(503);
        exit('The database is not ready. Complete the setup steps in README.md and refresh this page.');
    }
    handle_post_action();
}

$publicPages = ['login', 'setup', 'register', 'check-email'];
$appPages = ['dashboard', 'transactions', 'recurring', 'statement', 'household'];
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'dashboard';
$currentUser = null;

if ($page === 'setup') {
    $page = 'register';
}
$legacyStatement = $page === 'balance-sheet';
if ($legacyStatement) {
    $page = 'statement';
}
$legacyInvite = $page === 'invite';
if ($legacyInvite) {
    $page = 'household';
}

if (!$setupError && $page === 'confirm') {
    complete_email_confirmation_from_query();
}

if (!$setupError) {
    $currentUser = current_user();

    if ($page === 'household_sync') {
        header('Content-Type: application/json; charset=UTF-8');
        if ($currentUser === null) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthenticated']);
            exit;
        }
        echo json_encode([
            'version' => (int) ($currentUser['household_state_version'] ?? 0),
        ]);
        exit;
    }

    if ($currentUser === null) {
        if (!in_array($page, $publicPages, true)) {
            if ($page === 'settings') {
                redirect('login', ['next' => 'dashboard']);
            }
            $query = in_array($page, $appPages, true) ? ['next' => $page] : [];
            redirect('login', $query);
        }
    } else {
        if (in_array($page, $publicPages, true)) {
            redirect('dashboard');
        }
        if ($page === 'settings') {
            redirect('dashboard', ['profile' => '1']);
        }
        if ($legacyInvite) {
            redirect('household');
        }
        if ($legacyStatement) {
            $query = [];
            if (isset($_GET['from']) && is_string($_GET['from'])) {
                $query['from'] = $_GET['from'];
            }
            if (isset($_GET['to']) && is_string($_GET['to'])) {
                $query['to'] = $_GET['to'];
            }
            redirect('statement', $query);
        }
        if (!in_array($page, $appPages, true)) {
            http_response_code(404);
            $page = 'not-found';
        } else {
            materialise_due_recurring_entries(null, current_household_id());
        }
    }
} elseif (!in_array($page, $appPages, true)) {
    $page = 'dashboard';
}

$titles = [
    'dashboard' => 'Overview',
    'transactions' => 'Transactions',
    'recurring' => 'Recurring entries',
    'statement' => 'Statement',
    'household' => 'Household',
    'not-found' => 'Page not found',
    'login' => 'Sign in',
    'register' => 'Create your household',
    'check-email' => 'Confirm your email',
    'setup' => 'Create your household',
];
$pageTitle = $titles[$page] ?? 'HomeLedger';
$openInvite = null;
$inviteProblem = null;
$inviteToken = is_string($_GET['invite'] ?? null) ? trim($_GET['invite']) : '';
if (!$setupError && $page === 'register' && $inviteToken !== '') {
    try {
        $foundInvite = find_invite_by_raw_token($inviteToken);
        if ($foundInvite !== null && invite_is_open($foundInvite)) {
            $openInvite = $foundInvite;
            $pageTitle = 'Join household';
        } else {
            $inviteProblem = 'This invite is invalid, expired or already used. You can create your own household below.';
        }
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        $inviteProblem = 'This invite is invalid, expired or already used. You can create your own household below.';
    }
}
$flash = pull_flash();
$useAuthLayout = !$setupError && in_array($page, $publicPages, true);

require dirname(__DIR__) . '/templates/' . ($useAuthLayout ? 'auth-layout.php' : 'layout.php');
