<?php

declare(strict_types=1);

function member_removed_mail_plain(string $householdName): string
{
    return 'You were removed from ' . $householdName . " on HomeLedger.\n\n"
        . "You no longer have access to that household ledger.\n\n"
        . "If you did not expect this, contact the household owner.\n\n"
        . "HomeLedger - Home money, clearly.\n"
        . mail_footer_host() . "\n";
}

function member_removed_mail_html(string $householdName): string
{
    $safeHousehold = e($householdName);

    return account_mail_html(
        'You were removed from a household',
        'Household',
        'You were removed from ' . $householdName . ' on HomeLedger.',
        'You were removed from <strong style="color:#f5f4ef;">' . $safeHousehold . '</strong> on HomeLedger. You no longer have access to that household ledger.',
        '',
        '',
        '',
        'If you did not expect this, contact the household owner.'
    );
}

function send_member_removed_mail(string $to, string $householdName): string
{
    return send_account_notice_mail(
        $to,
        'You were removed from a HomeLedger household',
        member_removed_mail_plain($householdName),
        member_removed_mail_html($householdName),
        'member removed'
    );
}

function remove_household_member(
    int $memberId,
    string $password,
    string $typedHouseholdId,
    bool $sendMail = true
): void {
    assert_household_owner('Only the household owner can remove members.');

    $user = current_user();
    if ($user === null) {
        throw new RuntimeException('Sign in to remove a household member.');
    }

    $ownerId = (int) $user['id'];
    $householdId = (int) $user['household_id'];
    if ($memberId < 1 || $memberId === $ownerId) {
        throw new InvalidArgumentException('You cannot remove yourself from the household.');
    }

    $pdo = db();
    $ownerAccount = household_member_row($pdo, $householdId, $ownerId);
    if ($ownerAccount === null || !password_verify($password, (string) $ownerAccount['password_hash'])) {
        throw new InvalidArgumentException('The current password is not correct.');
    }

    $expectedCode = (string) ($user['household_public_code'] ?? '');
    assert_household_id_confirmation(
        $typedHouseholdId,
        $expectedCode,
        'Type the household ID exactly to confirm removal.'
    );

    $member = household_member_row($pdo, $householdId, $memberId);
    if ($member === null) {
        throw new InvalidArgumentException('That person is not a member of this household.');
    }
    if ($memberId === household_owner_user_id($householdId)) {
        throw new InvalidArgumentException('The household owner cannot be removed.');
    }

    $removedEmail = (string) $member['login'];
    $removedName = (string) $member['display_name'];
    $householdName = (string) ($user['household_name'] ?? 'this household');

    if ($sendMail) {
        send_member_removed_mail($removedEmail, $householdName);
    }

    $pdo->beginTransaction();
    try {
        $live = household_member_row($pdo, $householdId, $memberId);
        if ($live === null) {
            throw new InvalidArgumentException('That person is not a member of this household.');
        }
        if ($memberId === household_owner_user_id($householdId) || $memberId === $ownerId) {
            throw new InvalidArgumentException('The household owner cannot be removed.');
        }

        reassign_invites_from_user($pdo, $householdId, $memberId, $ownerId);
        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ? AND household_id = ?');
        $deleteUser->execute([$memberId, $householdId]);
        if ($deleteUser->rowCount() !== 1) {
            throw new InvalidArgumentException('That person could not be removed.');
        }
        log_household_activity(
            $householdId,
            $ownerId,
            'member_removed',
            'Removed ' . $removedName . ' (' . $removedEmail . ')',
            $pdo
        );
        bump_household_state($pdo, $householdId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
