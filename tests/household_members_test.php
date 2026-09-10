<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;

function household_members_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $message . "\n");
}

function household_members_exception_message(callable $callback): string
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }

    return '';
}

function household_members_cleanup(PDO $pdo, array $userIds, array $householdIds): void
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
        wipe_household_dependent_rows($pdo, $householdId);
        $pdo->prepare('DELETE FROM users WHERE household_id = ?')->execute([$householdId]);
        $pdo->prepare('DELETE FROM households WHERE id = ?')->execute([$householdId]);
    }
}

$removedPlain = member_removed_mail_plain('Oak House');
$removedHtml = member_removed_mail_html('Oak House');
household_members_assert(str_contains($removedPlain, 'Oak House'), 'Removal mail should include the household name.');
household_members_assert(str_contains($removedHtml, 'Oak House'), 'Removal HTML should include the household name.');
household_members_assert(!str_contains($removedPlain, '—') && !str_contains($removedHtml, '—'), 'Removal mail should not use an em dash.');
household_members_assert(str_contains($removedHtml, '#080b0f'), 'Removal mail should stay Kokoszone branded.');

$_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];
$pdo = db();
$stamp = bin2hex(random_bytes(4));
$password = 'remove-member-ok';
$hash = password_hash($password, PASSWORD_DEFAULT);
$createdHouseholds = [];
$createdUsers = [];
$household1Users = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE household_id = 1')->fetchColumn();

try {
    $insertHousehold = $pdo->prepare('INSERT INTO households (name, public_code, state_version) VALUES (?, ?, 1)');
    $insertHousehold->execute(['Member Test ' . $stamp, allocate_household_public_code($pdo)]);
    $householdId = (int) $pdo->lastInsertId();
    $createdHouseholds[] = $householdId;
    household_members_assert($householdId !== 1, 'Throwaway household must not reuse household 1.');

    $insertUser = $pdo->prepare(
        'INSERT INTO users (household_id, login, display_name, password_hash, email_verified_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $insertUser->execute([$householdId, 'hl-mem-owner-' . $stamp . '@example.test', 'Owner Mem', $hash]);
    $ownerId = (int) $pdo->lastInsertId();
    $insertUser->execute([$householdId, 'hl-mem-member-' . $stamp . '@example.test', 'Member Mem', $hash]);
    $memberId = (int) $pdo->lastInsertId();
    $insertUser->execute([$householdId, 'hl-mem-keep-' . $stamp . '@example.test', 'Keep Mem', $hash]);
    $keepId = (int) $pdo->lastInsertId();
    $createdUsers = [$ownerId, $memberId, $keepId];
    $pdo->prepare('UPDATE households SET owner_user_id = ? WHERE id = ?')->execute([$ownerId, $householdId]);

    $codeStmt = $pdo->prepare('SELECT public_code FROM households WHERE id = ?');
    $codeStmt->execute([$householdId]);
    $code = (string) $codeStmt->fetchColumn();

    $_SESSION['user_id'] = $ownerId;
    log_household_activity($householdId, $ownerId, 'invited', 'Invited hl-mem-member-' . $stamp . '@example.test');
    log_household_activity($householdId, $memberId, 'joined', 'Member Mem joined the household');

    $invite = $pdo->prepare(
        'INSERT INTO household_invites (household_id, invited_by_user_id, email, token_hash, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))'
    );
    $invite->execute([$householdId, $memberId, 'hl-mem-invite-' . $stamp . '@example.test', hash('sha256', 'token-' . $stamp)]);

    $selfBlocked = household_members_exception_message(static function () use ($ownerId, $password, $code): void {
        remove_household_member($ownerId, $password, $code, false);
    });
    household_members_assert(str_contains($selfBlocked, 'cannot remove yourself'), 'Owner must not remove themselves.');

    $ownerBlocked = household_members_exception_message(static function () use ($ownerId, $password, $code): void {
        remove_household_member($ownerId, $password, $code, false);
    });
    household_members_assert($ownerBlocked !== '', 'The owner row must not be removable.');

    $_SESSION['user_id'] = $memberId;
    $memberBlocked = household_members_exception_message(static function () use ($keepId, $password, $code): void {
        remove_household_member($keepId, $password, $code, false);
    });
    household_members_assert(
        str_contains($memberBlocked, 'Only the household owner can remove members.'),
        'Members must not remove other members.'
    );

    $_SESSION['user_id'] = $ownerId;
    $badPassword = household_members_exception_message(static function () use ($memberId, $code): void {
        remove_household_member($memberId, 'wrong-password-xx', $code, false);
    });
    household_members_assert(str_contains($badPassword, 'current password'), 'Wrong password should reject removal.');

    $badCode = household_members_exception_message(static function () use ($memberId, $password): void {
        remove_household_member($memberId, $password, 'ZZZZ-ZZZZ-ZZZZ-ZZZZ', false);
    });
    household_members_assert(str_contains($badCode, 'household ID'), 'Wrong household ID should reject removal.');

    $before = household_state_version($householdId);
    remove_household_member($memberId, $password, strtolower(str_replace('-', ' ', $code)), false);
    $gone = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $gone->execute([$memberId]);
    household_members_assert((int) $gone->fetchColumn() === 0, 'Owner should be able to remove a non-owner member.');
    $kept = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND household_id = ?');
    $kept->execute([$keepId, $householdId]);
    household_members_assert((int) $kept->fetchColumn() === 1, 'Removing one member should keep the others.');
    household_members_assert(household_state_version($householdId) === $before + 1, 'Removing a member should bump household state.');

    $inviteOwner = $pdo->prepare('SELECT invited_by_user_id FROM household_invites WHERE household_id = ? AND email = ?');
    $inviteOwner->execute([$householdId, 'hl-mem-invite-' . $stamp . '@example.test']);
    household_members_assert(
        (int) $inviteOwner->fetchColumn() === $ownerId,
        'Invites from the removed member should be reassigned to the owner.'
    );

    $_SESSION['user_id'] = $ownerId;
    $activity = household_activity_for_current(50);
    $keys = array_column($activity, 'event_key');
    household_members_assert(in_array('invited', $keys, true), 'Activity should include invited.');
    household_members_assert(in_array('joined', $keys, true), 'Activity should include joined.');
    household_members_assert(in_array('member_removed', $keys, true), 'Removing a member should write an activity row.');

    $renameBefore = household_state_version($householdId);
    update_current_household_name('Member Test Renamed ' . $stamp);
    household_members_assert(household_state_version($householdId) === $renameBefore + 1, 'Rename should bump household state.');
    $activity = household_activity_for_current(50);
    household_members_assert(
        in_array('household_renamed', array_column($activity, 'event_key'), true),
        'Renaming the household should write an activity row.'
    );
} catch (Throwable $exception) {
    household_members_assert(false, 'Household member tests failed: ' . $exception->getMessage());
} finally {
    $_SESSION = [];
    try {
        household_members_cleanup($pdo, $createdUsers, $createdHouseholds);
    } catch (Throwable $exception) {
        household_members_assert(false, 'Household member cleanup failed: ' . $exception->getMessage());
    }
}

$afterHousehold1Users = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE household_id = 1')->fetchColumn();
household_members_assert($afterHousehold1Users === $household1Users, 'Household 1 users must stay untouched.');

if ($failures > 0) {
    fwrite(STDERR, "Household member tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Household member tests passed.\n");
exit(0);
