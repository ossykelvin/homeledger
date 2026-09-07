<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;

function google_oauth_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $message . "\n");
}

function google_oauth_cleanup(PDO $pdo, array $userIds, array $householdIds): void
{
    foreach ($userIds as $userId) {
        if ($userId < 1) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT household_id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $householdId = (int) $stmt->fetchColumn();
        if ($householdId > 0 && $householdId !== 1) {
            $pdo->prepare('UPDATE households SET owner_user_id = NULL WHERE id = ? AND owner_user_id = ?')
                ->execute([$householdId, $userId]);
            $pdo->prepare('DELETE FROM household_invites WHERE invited_by_user_id = ?')->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
    foreach ($householdIds as $householdId) {
        if ($householdId < 2) {
            continue;
        }
        $pdo->prepare('UPDATE households SET owner_user_id = NULL WHERE id = ?')->execute([$householdId]);
        $pdo->prepare('DELETE FROM household_invites WHERE household_id = ?')->execute([$householdId]);
        $pdo->prepare('DELETE FROM transactions WHERE household_id = ?')->execute([$householdId]);
        $pdo->prepare('DELETE FROM recurring_entries WHERE household_id = ?')->execute([$householdId]);
        $pdo->prepare('DELETE FROM categories WHERE household_id = ?')->execute([$householdId]);
        $pdo->prepare('DELETE FROM users WHERE household_id = ?')->execute([$householdId]);
        $pdo->prepare('DELETE FROM households WHERE id = ?')->execute([$householdId]);
    }
}

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/009_google_sub.sql');
$schema = (string) file_get_contents($root . '/database/schema.sql');
$schemaImport = (string) file_get_contents($root . '/database/schema_import.sql');
$envExample = (string) file_get_contents($root . '/.env.example');
$compose = (string) file_get_contents($root . '/docker-compose.yml');
$login = (string) file_get_contents($root . '/templates/pages/login.php');
$register = (string) file_get_contents($root . '/templates/pages/register.php');
$sw = (string) file_get_contents($root . '/public/service-worker.js');
$auth = (string) file_get_contents($root . '/app/GoogleAuth.php');
$index = (string) file_get_contents($root . '/public/index.php');

google_oauth_assert(str_contains($migration, 'google_sub VARCHAR(255) NULL'), 'Migration 009 should add nullable google_sub.');
google_oauth_assert(str_contains($migration, 'users_google_sub_unique'), 'Migration 009 should add a unique google_sub key.');
google_oauth_assert(!str_contains($migration, 'UPDATE users'), 'Migration 009 should not backfill google_sub.');
google_oauth_assert(str_contains($schema, 'google_sub VARCHAR(255) NULL'), 'schema.sql should include google_sub.');
google_oauth_assert(str_contains($schema, 'users_google_sub_unique'), 'schema.sql should unique-index google_sub.');
google_oauth_assert(str_contains($schemaImport, 'google_sub VARCHAR(255) NULL'), 'schema_import.sql should include google_sub.');
google_oauth_assert(str_contains($schemaImport, 'users_google_sub_unique'), 'schema_import.sql should unique-index google_sub.');
google_oauth_assert(preg_match('/^GOOGLE_CLIENT_ID=$/m', $envExample) === 1, '.env.example should include empty GOOGLE_CLIENT_ID.');
google_oauth_assert(preg_match('/^GOOGLE_CLIENT_SECRET=$/m', $envExample) === 1, '.env.example should include empty GOOGLE_CLIENT_SECRET.');
google_oauth_assert(str_contains($compose, 'GOOGLE_CLIENT_ID: ${GOOGLE_CLIENT_ID:-}'), 'Compose should pass GOOGLE_CLIENT_ID from env.');
google_oauth_assert(str_contains($compose, 'GOOGLE_CLIENT_SECRET: ${GOOGLE_CLIENT_SECRET:-}'), 'Compose should pass GOOGLE_CLIENT_SECRET from env.');
google_oauth_assert(str_contains($login, 'google-signin-button.php'), 'Login should include the Google button partial.');
google_oauth_assert(str_contains($register, 'google-signin-button.php'), 'Register should include the Google button partial.');
google_oauth_assert(str_contains($sw, 'homeledger-shell-v22'), 'Service worker should bump cache after adding the Google logo.');
google_oauth_assert(str_contains($sw, 'google-g.png'), 'Service worker should cache the Google G logo.');
google_oauth_assert(str_contains($index, "page === 'google'"), 'Front controller should handle Google start.');
google_oauth_assert(str_contains($index, 'google-callback'), 'Front controller should handle Google callback.');
google_oauth_assert(str_contains($auth, 'hash_equals'), 'Google OAuth should compare state with hash_equals.');
google_oauth_assert(!str_contains($auth, '—'), 'Google OAuth copy should not use an em dash.');
google_oauth_assert(!str_contains($login, '—') && !str_contains($register, '—'), 'Auth pages should not use an em dash.');

$logo = $root . '/public/assets/brand/google-g.png';
google_oauth_assert(is_file($logo) && filesize($logo) > 1000, 'Google G logo should be stored under public/assets/brand.');

global $config;
$previousGoogle = $config['google'] ?? ['client_id' => '', 'client_secret' => ''];
$previousUrl = $config['app']['url'] ?? '';
$previousHost = $_SERVER['HTTP_HOST'] ?? null;
$previousHttps = $_SERVER['HTTPS'] ?? null;

$config['google'] = ['client_id' => '', 'client_secret' => ''];
google_oauth_assert(google_oauth_is_configured() === false, 'Empty Google credentials should hide the button.');
ob_start();
require $root . '/templates/partials/google-signin-button.php';
$hidden = ob_get_clean();
google_oauth_assert(
    $hidden === '' || !str_contains($hidden, 'Sign in with Google'),
    'The Google button must not render when the client id is missing.'
);

$config['google'] = [
    'client_id' => 'test-client.apps.googleusercontent.com',
    'client_secret' => 'test-secret',
];
google_oauth_assert(google_oauth_is_configured() === true, 'Both Google credentials should enable the button.');
$googleNext = 'transactions';
ob_start();
require $root . '/templates/partials/google-signin-button.php';
$shown = ob_get_clean();
google_oauth_assert(substr_count($shown, 'Sign in with Google') === 1, 'The Google button should render once.');
google_oauth_assert(substr_count((string) file_get_contents($root . '/templates/partials/google-signin-button.php'), 'google-auth-button') === 1, 'The Google button partial should contain one button.');
google_oauth_assert(str_contains($shown, 'assets/brand/google-g.png'), 'Configured Google sign-in should use the attached G logo.');
google_oauth_assert(str_contains($shown, 'page=google'), 'The button should start OAuth at page=google.');
google_oauth_assert(str_contains($shown, 'next=transactions'), 'Login next should be forwarded to Google start.');
google_oauth_assert(!str_contains($shown, '—'), 'Google button markup should not use an em dash.');

$config['app']['url'] = 'https://homeledger.koptechnology.co.uk';
$_SERVER['HTTP_HOST'] = 'homeledger.koptechnology.co.uk';
$_SERVER['HTTPS'] = 'on';
google_oauth_assert(
    google_oauth_redirect_uri() === 'https://homeledger.koptechnology.co.uk/?page=google-callback',
    'Production redirect URI should follow rtrim(APP_URL) plus /?page=google-callback.'
);

$_SERVER['HTTP_HOST'] = 'localhost:8080';
unset($_SERVER['HTTPS']);
google_oauth_assert(
    google_oauth_redirect_uri() === 'http://localhost:8080/?page=google-callback',
    'Docker localhost should use http://localhost:8080/?page=google-callback.'
);

$config['google'] = $previousGoogle;
$config['app']['url'] = $previousUrl;
if ($previousHost === null) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $previousHost;
}
if ($previousHttps === null) {
    unset($_SERVER['HTTPS']);
} else {
    $_SERVER['HTTPS'] = $previousHttps;
}

google_oauth_assert(
    clip_google_display_name('Ada Lovelace', 'ada@example.test') === 'Ada Lovelace',
    'Google display names should be kept when they fit.'
);
google_oauth_assert(
    clip_google_display_name('', 'ada.lovelace@example.test') === 'ada.lovelace',
    'Missing Google names should fall back to the email local part.'
);

$pdo = db();
$_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];
$stamp = bin2hex(random_bytes(4));
$createdHouseholds = [];
$createdUsers = [];
$prefix = 'hl-ggl-' . $stamp;

try {
    $household1 = (int) $pdo->query('SELECT COUNT(*) FROM households WHERE id = 1')->fetchColumn();
    google_oauth_assert($household1 === 1, 'Household 1 must exist before Google tests.');

    $insertHousehold = $pdo->prepare('INSERT INTO households (name, public_code, state_version) VALUES (?, ?, 1)');
    $insertHousehold->execute(['Google Owner ' . $stamp, allocate_household_public_code($pdo)]);
    $ownerHousehold = (int) $pdo->lastInsertId();
    $createdHouseholds[] = $ownerHousehold;
    google_oauth_assert($ownerHousehold !== 1, 'Throwaway households must not reuse household 1.');

    $ownerEmail = $prefix . '-owner@example.test';
    $ownerInsert = $pdo->prepare(
        'INSERT INTO users (household_id, login, display_name, password_hash, email_verified_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $ownerInsert->execute([$ownerHousehold, $ownerEmail, 'Owner G', password_hash('google-owner-pass', PASSWORD_DEFAULT)]);
    $ownerId = (int) $pdo->lastInsertId();
    $createdUsers[] = $ownerId;
    $pdo->prepare('UPDATE households SET owner_user_id = ? WHERE id = ?')->execute([$ownerId, $ownerHousehold]);

    $existingEmail = $prefix . '-exist@example.test';
    $lockUntil = (new DateTimeImmutable('+' . AUTH_LOCK_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
    $existInsert = $pdo->prepare(
        'INSERT INTO users (household_id, login, display_name, password_hash, failed_attempts, locked_until)
         VALUES (?, ?, ?, ?, 4, ?)'
    );
    $existInsert->execute([
        $ownerHousehold,
        $existingEmail,
        'Locked G',
        password_hash('google-exist-pass', PASSWORD_DEFAULT),
        $lockUntil,
    ]);
    $existingId = (int) $pdo->lastInsertId();
    $createdUsers[] = $existingId;

    $locked = false;
    try {
        complete_google_sign_in([
            'sub' => 'google-sub-locked-' . $stamp,
            'email' => $existingEmail,
            'name' => 'Locked G',
        ]);
    } catch (InvalidArgumentException $exception) {
        $locked = $exception->getMessage() === AUTH_LOCK_MESSAGE;
    }
    google_oauth_assert($locked, 'Google sign-in should honour locked_until.');

    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$existingId]);
    $linked = complete_google_sign_in([
        'sub' => 'google-sub-exist-' . $stamp,
        'email' => $existingEmail,
        'name' => 'Linked G',
    ]);
    google_oauth_assert((int) $linked['id'] === $existingId, 'Existing email should sign in without creating a user.');
    google_oauth_assert($linked['created'] === false, 'Existing email should not create a household owner.');
    $linkedRow = $pdo->prepare(
        'SELECT google_sub, email_verified_at, household_id FROM users WHERE id = ?'
    );
    $linkedRow->execute([$existingId]);
    $linkedUser = $linkedRow->fetch();
    google_oauth_assert(is_array($linkedUser), 'Linked Google user should still exist.');
    google_oauth_assert((string) $linkedUser['google_sub'] === 'google-sub-exist-' . $stamp, 'Existing email should store google_sub.');
    google_oauth_assert(!empty($linkedUser['email_verified_at']), 'Google sign-in should treat the email as verified.');
    google_oauth_assert((int) $linkedUser['household_id'] === $ownerHousehold, 'Existing email must stay in its household.');

    $newEmail = $prefix . '-new@example.test';
    $created = complete_google_sign_in([
        'sub' => 'google-sub-new-' . $stamp,
        'email' => $newEmail,
        'name' => 'New Google',
    ]);
    $createdUsers[] = (int) $created['id'];
    google_oauth_assert($created['created'] === true, 'A new Google email should create an account.');
    $newRow = $pdo->prepare(
        'SELECT household_id, google_sub, email_verified_at, email_confirm_token_hash FROM users WHERE id = ?'
    );
    $newRow->execute([(int) $created['id']]);
    $newUser = $newRow->fetch();
    google_oauth_assert(is_array($newUser), 'New Google user should be stored.');
    $newHousehold = (int) $newUser['household_id'];
    $createdHouseholds[] = $newHousehold;
    google_oauth_assert($newHousehold !== 1 && $newHousehold !== $ownerHousehold, 'New Google email should get its own household.');
    google_oauth_assert(!empty($newUser['email_verified_at']), 'New Google users should be verified immediately.');
    google_oauth_assert($newUser['email_confirm_token_hash'] === null, 'New Google users should skip confirm email.');
    $seeded = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE household_id = ?');
    $seeded->execute([$newHousehold]);
    google_oauth_assert((int) $seeded->fetchColumn() > 0, 'New Google households should receive seeded categories.');

    $inviteEmail = $prefix . '-join@example.test';
    $rawInvite = bin2hex(random_bytes(32));
    $inviteInsert = $pdo->prepare(
        'INSERT INTO household_invites (household_id, invited_by_user_id, email, token_hash, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
    );
    $inviteInsert->execute([$ownerHousehold, $ownerId, $inviteEmail, hash_invite_token($rawInvite)]);
    $joined = complete_google_sign_in([
        'sub' => 'google-sub-join-' . $stamp,
        'email' => $inviteEmail,
        'name' => 'Join Google',
    ], $rawInvite);
    $createdUsers[] = (int) $joined['id'];
    google_oauth_assert($joined['joined'] === true, 'Invite Google sign-in should join the household.');
    $joinRow = $pdo->prepare('SELECT household_id, email_verified_at FROM users WHERE id = ?');
    $joinRow->execute([(int) $joined['id']]);
    $joinUser = $joinRow->fetch();
    google_oauth_assert(is_array($joinUser) && (int) $joinUser['household_id'] === $ownerHousehold, 'Invite Google sign-in should not create a second household.');
    google_oauth_assert(!empty($joinUser['email_verified_at']), 'Invite Google sign-in should skip extra email confirmation.');
} catch (Throwable $exception) {
    google_oauth_assert(false, 'Google database tests failed: ' . $exception->getMessage());
} finally {
    $_SESSION = [];
    try {
        google_oauth_cleanup($pdo, $createdUsers, $createdHouseholds);
    } catch (Throwable $exception) {
        google_oauth_assert(false, 'Google test cleanup failed: ' . $exception->getMessage());
    }
}

$leftover = $pdo->prepare('SELECT COUNT(*) FROM users WHERE login LIKE ?');
$leftover->execute([$prefix . '%@example.test']);
google_oauth_assert((int) $leftover->fetchColumn() === 0, 'Throwaway Google users should be cleaned up.');
google_oauth_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM households WHERE id = 1')->fetchColumn() === 1,
    'Household 1 must still exist after Google tests.'
);

if ($failures > 0) {
    fwrite(STDERR, "Google OAuth tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Google OAuth tests passed.\n");
exit(0);
