<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;

function account_delete_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $message . "\n");
}

account_delete_assert(
    household_public_codes_equal('A3K9-M2PQ-7X2B-Q8NL', 'a3k9 m2pq 7x2b q8nl'),
    'Household ID confirmation should ignore case, spaces and hyphens.'
);
account_delete_assert(
    !household_public_codes_equal('A3K9-M2PQ-7X2B-Q8NL', '1'),
    'Numeric internal household IDs must not confirm deletion.'
);
account_delete_assert(
    !household_public_codes_equal('A3K9-M2PQ-7X2B-Q8NL', 'A3K9-M2PQ-7X2B-Q8NM'),
    'A different public household ID must not confirm deletion.'
);

$mismatchRejected = false;
try {
    assert_household_id_confirmation('1', 'A3K9-M2PQ-7X2B-Q8NL');
} catch (InvalidArgumentException $exception) {
    $mismatchRejected = str_contains($exception->getMessage(), 'not a number');
}
account_delete_assert($mismatchRejected, 'Typing an internal numeric id should be rejected.');

global $config;
$previousAppUrl = $config['app']['url'] ?? '';
$config['app']['url'] = 'https://homeledger.koptechnology.co.uk';

$deletedPlain = account_deleted_mail_plain(true);
$deletedHtml = account_deleted_mail_html(true);
$ownerPlain = new_owner_mail_plain('Oak House', 'A3K9-M2PQ-7X2B-Q8NL');
$ownerHtml = new_owner_mail_html('Oak House', 'A3K9-M2PQ-7X2B-Q8NL');

account_delete_assert(str_contains($deletedPlain, 'homeledger.koptechnology.co.uk'), 'Deletion mail should include the APP_URL host.');
account_delete_assert(str_contains($deletedHtml, 'homeledger.koptechnology.co.uk'), 'Deletion HTML should include the APP_URL host.');
account_delete_assert(!str_contains($deletedPlain, '—') && !str_contains($deletedHtml, '—'), 'Deletion mail should not use an em dash.');
account_delete_assert(str_contains($ownerPlain, 'Oak House'), 'New owner mail should include the household name.');
account_delete_assert(str_contains($ownerPlain, 'A3K9-M2PQ-7X2B-Q8NL'), 'New owner mail should include the household ID.');
account_delete_assert(str_contains($ownerHtml, 'A3K9-M2PQ-7X2B-Q8NL'), 'New owner HTML should include the household ID.');
account_delete_assert(!str_contains($ownerPlain, '—') && !str_contains($ownerHtml, '—'), 'New owner mail should not use an em dash.');
account_delete_assert(str_contains($deletedHtml, '#080b0f') && str_contains($ownerHtml, '#c7f36b'), 'Account mails should stay Kokoszone branded.');

$migration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/008_household_owner_user.sql');
account_delete_assert(str_contains($migration, 'owner_user_id'), 'Migration 008 should add owner_user_id.');
$schema = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');
account_delete_assert(str_contains($schema, 'owner_user_id BIGINT UNSIGNED NULL'), 'Fresh schema should include owner_user_id.');
account_delete_assert(str_contains($schema, 'household_owner_user_fk'), 'Fresh schema should add the owner foreign key.');

$config['app']['url'] = $previousAppUrl;

$pdo = db();
$_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];
$stamp = bin2hex(random_bytes(4));
$password = 'delete-account-ok';
$hash = password_hash($password, PASSWORD_DEFAULT);
$createdHouseholds = [];
$createdUsers = [];

function account_delete_insert_household(PDO $pdo, string $name, array &$createdHouseholds): int
{
    $stmt = $pdo->prepare('INSERT INTO households (name, public_code, state_version) VALUES (?, ?, 1)');
    $stmt->execute([$name, allocate_household_public_code($pdo)]);
    $id = (int) $pdo->lastInsertId();
    $createdHouseholds[] = $id;
    account_delete_assert($id !== 1, 'Throwaway households must not reuse household 1.');

    return $id;
}

function account_delete_insert_user(
    PDO $pdo,
    int $householdId,
    string $email,
    string $name,
    string $hash,
    array &$createdUsers,
    bool $asOwner = false
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO users (household_id, login, display_name, password_hash, email_verified_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$householdId, $email, $name, $hash]);
    $id = (int) $pdo->lastInsertId();
    $createdUsers[] = $id;
    if ($asOwner) {
        $pdo->prepare('UPDATE households SET owner_user_id = ? WHERE id = ?')->execute([$id, $householdId]);
    }

    return $id;
}

function account_delete_cleanup(PDO $pdo, array $userIds, array $householdIds): void
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

try {
    $transferHousehold = account_delete_insert_household($pdo, 'Delete Transfer ' . $stamp, $createdHouseholds);
    $outsiderHousehold = account_delete_insert_household($pdo, 'Delete Outsider ' . $stamp, $createdHouseholds);
    $lastHousehold = account_delete_insert_household($pdo, 'Delete Last ' . $stamp, $createdHouseholds);
    $leaveHousehold = account_delete_insert_household($pdo, 'Delete Leave ' . $stamp, $createdHouseholds);
    $mismatchHousehold = account_delete_insert_household($pdo, 'Delete Mismatch ' . $stamp, $createdHouseholds);

    $ownerA = account_delete_insert_user($pdo, $transferHousehold, 'hl-del-owner-' . $stamp . '@example.test', 'Owner A', $hash, $createdUsers, true);
    $memberB = account_delete_insert_user($pdo, $transferHousehold, 'hl-del-member-' . $stamp . '@example.test', 'Member B', $hash, $createdUsers);
    $outsiderC = account_delete_insert_user($pdo, $outsiderHousehold, 'hl-del-out-' . $stamp . '@example.test', 'Outsider C', $hash, $createdUsers, true);
    $soloD = account_delete_insert_user($pdo, $lastHousehold, 'hl-del-solo-' . $stamp . '@example.test', 'Solo D', $hash, $createdUsers, true);
    $ownerE = account_delete_insert_user($pdo, $leaveHousehold, 'hl-del-keep-' . $stamp . '@example.test', 'Owner E', $hash, $createdUsers, true);
    $memberF = account_delete_insert_user($pdo, $leaveHousehold, 'hl-del-leave-' . $stamp . '@example.test', 'Member F', $hash, $createdUsers);
    $soloG = account_delete_insert_user($pdo, $mismatchHousehold, 'hl-del-miss-' . $stamp . '@example.test', 'Solo G', $hash, $createdUsers, true);

    $pdo->prepare('INSERT INTO categories (household_id, name, type, colour, sort_order) VALUES (?, ?, ?, ?, ?)')
        ->execute([$lastHousehold, 'Throwaway', 'expense', '#ff826b', 10]);

    $code = static function (PDO $pdo, int $householdId): string {
        $stmt = $pdo->prepare('SELECT public_code FROM households WHERE id = ?');
        $stmt->execute([$householdId]);

        return (string) $stmt->fetchColumn();
    };

    $_SESSION['user_id'] = $ownerA;
    $outsiderBlocked = false;
    try {
        delete_current_user_account($password, $code($pdo, $transferHousehold), $outsiderC, false);
    } catch (InvalidArgumentException $exception) {
        $outsiderBlocked = str_contains($exception->getMessage(), 'current member');
    }
    account_delete_assert($outsiderBlocked, 'Owners must not transfer to a user outside the household.');
    account_delete_assert(
        household_owner_user_id($transferHousehold) === $ownerA,
        'Failed outsider transfer must leave the original owner in place.'
    );
    $ownerStillThere = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $ownerStillThere->execute([$ownerA]);
    account_delete_assert((int) $ownerStillThere->fetchColumn() === 1, 'Failed outsider transfer must not delete the owner.');

    $_SESSION['user_id'] = $ownerA;
    delete_current_user_account($password, strtolower(str_replace('-', ' ', $code($pdo, $transferHousehold))), $memberB, false);
    $ownerGone = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $ownerGone->execute([$ownerA]);
    account_delete_assert((int) $ownerGone->fetchColumn() === 0, 'Owner transfer should delete the requesting user.');
    $householdKept = $pdo->prepare('SELECT owner_user_id FROM households WHERE id = ?');
    $householdKept->execute([$transferHousehold]);
    account_delete_assert((int) $householdKept->fetchColumn() === $memberB, 'Owner transfer should store the new owner_user_id.');
    $memberKept = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND household_id = ?');
    $memberKept->execute([$memberB, $transferHousehold]);
    account_delete_assert((int) $memberKept->fetchColumn() === 1, 'Owner transfer should keep the remaining member.');

    $_SESSION['user_id'] = $soloD;
    delete_current_user_account($password, $code($pdo, $lastHousehold), null, false);
    $soloGone = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $soloGone->execute([$soloD]);
    $houseGone = $pdo->prepare('SELECT COUNT(*) FROM households WHERE id = ?');
    $houseGone->execute([$lastHousehold]);
    $catsGone = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE household_id = ?');
    $catsGone->execute([$lastHousehold]);
    account_delete_assert((int) $soloGone->fetchColumn() === 0, 'Last-member delete should remove the user.');
    account_delete_assert((int) $houseGone->fetchColumn() === 0, 'Last-member delete should remove the household.');
    account_delete_assert((int) $catsGone->fetchColumn() === 0, 'Last-member delete should remove household categories.');

    $_SESSION['user_id'] = $memberF;
    delete_current_user_account($password, $code($pdo, $leaveHousehold), null, false);
    $memberGone = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $memberGone->execute([$memberF]);
    $leaveHouse = $pdo->prepare('SELECT owner_user_id FROM households WHERE id = ?');
    $leaveHouse->execute([$leaveHousehold]);
    $ownerKept = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND household_id = ?');
    $ownerKept->execute([$ownerE, $leaveHousehold]);
    account_delete_assert((int) $memberGone->fetchColumn() === 0, 'Member leave should delete only that user.');
    account_delete_assert((int) $leaveHouse->fetchColumn() === $ownerE, 'Member leave should keep the existing owner.');
    account_delete_assert((int) $ownerKept->fetchColumn() === 1, 'Member leave should keep the household and remaining owner.');

    $_SESSION['user_id'] = $soloG;
    $codeRejected = false;
    try {
        delete_current_user_account($password, 'ZZZZ-ZZZZ-ZZZZ-ZZZZ', null, false);
    } catch (InvalidArgumentException $exception) {
        $codeRejected = str_contains($exception->getMessage(), 'household ID');
    }
    account_delete_assert($codeRejected, 'A mismatched public household ID must reject deletion.');
    $mismatchKept = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $mismatchKept->execute([$soloG]);
    $mismatchHouse = $pdo->prepare('SELECT COUNT(*) FROM households WHERE id = ?');
    $mismatchHouse->execute([$mismatchHousehold]);
    account_delete_assert((int) $mismatchKept->fetchColumn() === 1, 'A public_code mismatch must not delete the user.');
    account_delete_assert((int) $mismatchHouse->fetchColumn() === 1, 'A public_code mismatch must not delete the household.');
} catch (Throwable $exception) {
    account_delete_assert(false, 'Account deletion tests failed: ' . $exception->getMessage());
} finally {
    $_SESSION = [];
    try {
        account_delete_cleanup($pdo, $createdUsers, $createdHouseholds);
    } catch (Throwable $exception) {
        account_delete_assert(false, 'Account deletion cleanup failed: ' . $exception->getMessage());
    }
}

$leftover = $pdo->prepare(
    "SELECT COUNT(*) FROM users WHERE login LIKE ?"
);
$leftover->execute(['hl-del-%' . $stamp . '@example.test']);
account_delete_assert((int) $leftover->fetchColumn() === 0, 'Throwaway deletion users should be cleaned up.');

$protected = $pdo->query('SELECT COUNT(*) FROM households WHERE id = 1')->fetchColumn();
account_delete_assert((int) $protected === 1, 'Household 1 must still exist after throwaway deletion tests.');

if ($failures > 0) {
    fwrite(STDERR, "Account deletion tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Account deletion tests passed.\n");
exit(0);
