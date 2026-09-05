<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;

function household_state_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $message . "\n");
}

function household_state_insert_user(PDO $pdo, int $householdId, string $email, string $name): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (household_id, login, display_name, password_hash) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$householdId, $email, $name, 'test-hash-not-for-login']);

    return (int) $pdo->lastInsertId();
}

$_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];

$pdo = db();
$stamp = bin2hex(random_bytes(4));
$householdIds = [];
$userIds = [];
$inviteIds = [];

try {
    $pdo->beginTransaction();

    $insertHousehold = $pdo->prepare('INSERT INTO households (name, public_code, state_version) VALUES (?, ?, 1)');
    $insertHousehold->execute(['State Test A ' . $stamp, allocate_household_public_code($pdo)]);
    $householdA = (int) $pdo->lastInsertId();
    $householdIds[] = $householdA;

    $insertHousehold->execute(['State Test B ' . $stamp, allocate_household_public_code($pdo)]);
    $householdB = (int) $pdo->lastInsertId();
    $householdIds[] = $householdB;

    $ownerEmail = 'hl-state-owner-' . $stamp . '@example.test';
    $memberEmail = 'hl-state-member-' . $stamp . '@example.test';
    $inviteEmail = 'hl-state-invitee-' . $stamp . '@example.test';
    $ownerId = household_state_insert_user($pdo, $householdA, $ownerEmail, 'Owner A');
    $memberId = household_state_insert_user($pdo, $householdA, $memberEmail, 'Member A');
    $otherOwnerId = household_state_insert_user($pdo, $householdB, 'hl-state-b-' . $stamp . '@example.test', 'Owner B');
    $userIds = [$ownerId, $memberId, $otherOwnerId];

    household_state_assert($householdA !== 1 && $householdB !== 1, 'Throwaway households must not reuse household 1.');
    household_state_assert(household_owner_user_id($householdA) === $ownerId, 'Earliest user should be the household owner.');
    household_state_assert(household_state_version($householdA) === 1, 'New household state version should start at 1.');
    household_state_assert(household_state_version($householdB) === 1, 'Second household should have its own starting version.');

    bump_household_state($pdo, $householdA);
    household_state_assert(household_state_version($householdA) === 2, 'bump_household_state should increment household A.');
    household_state_assert(household_state_version($householdB) === 1, 'Household B version must stay isolated from household A bumps.');

    $rawToken = bin2hex(random_bytes(32));
    $invite = $pdo->prepare(
        'INSERT INTO household_invites (household_id, invited_by_user_id, email, token_hash, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
    );
    $invite->execute([$householdA, $ownerId, $inviteEmail, hash_invite_token($rawToken)]);
    $inviteIds[] = (int) $pdo->lastInsertId();

    $acceptedHousehold = accept_open_invite($pdo, $rawToken, $inviteEmail);
    household_state_assert($acceptedHousehold === $householdA, 'Accepted invite should belong to household A.');
    household_state_assert(household_state_version($householdA) === 3, 'Accepting an invite should bump the household version.');
    household_state_assert(household_state_version($householdB) === 1, 'Accepting on household A must not bump household B.');

    $_SESSION['user_id'] = $memberId;
    $memberBlocked = false;
    try {
        create_household_invite('hl-state-forged-' . $stamp . '@example.test');
    } catch (InvalidArgumentException $exception) {
        $memberBlocked = str_contains($exception->getMessage(), 'Only the household owner can manage invites.');
    }
    household_state_assert($memberBlocked, 'Non-owner members must not create invites.');

    $resendBlocked = false;
    try {
        resend_household_invite($inviteIds[0]);
    } catch (InvalidArgumentException $exception) {
        $resendBlocked = str_contains($exception->getMessage(), 'Only the household owner can manage invites.');
    }
    household_state_assert($resendBlocked, 'Non-owner members must not resend invites.');

    $revokeBlocked = false;
    try {
        revoke_household_invite($inviteIds[0]);
    } catch (InvalidArgumentException $exception) {
        $revokeBlocked = str_contains($exception->getMessage(), 'Only the household owner can manage invites.');
    }
    household_state_assert($revokeBlocked, 'Non-owner members must not cancel invites.');

    $_SESSION['user_id'] = $ownerId;
    household_state_assert(current_user_is_household_owner(), 'Owner session should pass the owner check.');
    $_SESSION['user_id'] = $memberId;
    household_state_assert(!current_user_is_household_owner(), 'Member session should fail the owner check.');

    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    household_state_assert(false, 'Household state test setup failed: ' . $exception->getMessage());
}

$_SESSION = [];

if ($failures > 0) {
    fwrite(STDERR, "Household state tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Household state tests passed.\n");
exit(0);
