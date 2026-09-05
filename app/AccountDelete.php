<?php

declare(strict_types=1);

function mail_footer_host(): string
{
    $base = trim((string) config('app.url', ''));
    if ($base === '') {
        $base = app_public_url();
    }
    $host = parse_url($base, PHP_URL_HOST);

    return is_string($host) && $host !== '' ? $host : $base;
}

function account_mail_brand_mark(): string
{
    $logoSrc = '';
    $assetBase = mail_public_asset_base();
    if ($assetBase !== '') {
        $logoSrc = $assetBase . '/assets/brand/logo-dark.png';
    }

    if ($logoSrc !== '') {
        return '<img src="' . e($logoSrc) . '" width="180" alt="HomeLedger" style="display:block;width:180px;max-width:180px;height:auto;border:0;">';
    }

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0">'
        . '<tr>'
        . '<td style="width:28px;height:28px;border-radius:8px;background-color:#c7f36b;color:#080b0f;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:800;line-height:28px;text-align:center;">h</td>'
        . '<td style="padding-left:10px;color:#f5f4ef;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:700;letter-spacing:-0.03em;line-height:1;">HomeLedger</td>'
        . '</tr></table>';
}

function account_mail_html(
    string $title,
    string $eyebrow,
    string $preheader,
    string $introHtml,
    string $extraHtml = '',
    string $ctaLabel = '',
    string $ctaUrl = '',
    string $closingHtml = ''
): string {
    $safeTitle = e($title);
    $safeEyebrow = e($eyebrow);
    $safePreheader = e($preheader);
    $host = e(mail_footer_host());
    $brandMark = account_mail_brand_mark();
    $cta = '';
    if ($ctaLabel !== '' && $ctaUrl !== '') {
        $safeUrl = e($ctaUrl);
        $safeLabel = e($ctaLabel);
        $cta = '<tr>
<td align="center" style="padding:28px 32px 8px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
<tr>
<td align="center" bgcolor="#c7f36b" style="border-radius:999px;background-color:#c7f36b;">
<a href="' . $safeUrl . '" style="display:inline-block;padding:12px 22px;border:1px solid #c7f36b;border-radius:999px;background-color:#c7f36b;color:#080b0f;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;text-decoration:none;">' . $safeLabel . '</a>
</td>
</tr>
</table>
</td>
</tr>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title>' . $safeTitle . '</title>
</head>
<body style="margin:0;padding:0;background-color:#080b0f;color:#f5f4ef;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#080b0f;">' . $safePreheader . '</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#080b0f;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="width:560px;max-width:560px;border:1px solid rgba(255,255,255,0.12);border-radius:20px;background-color:#171c23;">
<tr>
<td style="height:4px;line-height:4px;font-size:0;background-color:#c7f36b;border-radius:20px 20px 0 0;">&nbsp;</td>
</tr>
<tr>
<td style="padding:32px 32px 12px;font-family:Arial,Helvetica,sans-serif;">
' . $brandMark . '
</td>
</tr>
<tr>
<td style="padding:8px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#c7f36b;font-size:11px;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;">
' . $safeEyebrow . '
</td>
</tr>
<tr>
<td style="padding:8px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#f5f4ef;font-size:28px;font-weight:700;letter-spacing:-0.04em;line-height:1.15;">
' . $safeTitle . '
</td>
</tr>
<tr>
<td style="padding:16px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:16px;line-height:1.5;">
' . $introHtml . '
</td>
</tr>
' . $cta . '
' . $extraHtml . '
' . ($closingHtml !== ''
        ? '<tr><td style="padding:24px 32px 32px;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:13px;line-height:1.5;">' . $closingHtml . '</td></tr>'
        : '') . '
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="width:560px;max-width:560px;">
<tr>
<td style="padding:18px 8px 0;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:12px;">
HomeLedger - Home money, clearly.<br>' . $host . '
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
';
}

function account_deleted_mail_plain(bool $householdDeleted): string
{
    $body = $householdDeleted
        ? "Your HomeLedger account and household have been deleted.\n\n"
        : "Your HomeLedger account has been deleted. You no longer have access to that household ledger.\n\n";

    return $body
        . "This cannot be undone.\n\n"
        . "If you did not delete this account, contact whoever still has access to the household.\n\n"
        . "HomeLedger - Home money, clearly.\n"
        . mail_footer_host() . "\n";
}

function account_deleted_mail_html(bool $householdDeleted): string
{
    $intro = $householdDeleted
        ? 'Your HomeLedger account and household have been deleted.'
        : 'Your HomeLedger account has been deleted. You no longer have access to that household ledger.';

    return account_mail_html(
        'Account deleted',
        'Account',
        'Your HomeLedger account has been deleted. This cannot be undone.',
        e($intro),
        '',
        '',
        '',
        'This cannot be undone.'
    );
}

function new_owner_mail_plain(string $householdName, string $publicCode): string
{
    return 'You are now the owner of ' . $householdName . " on HomeLedger.\n\n"
        . 'Household ID: ' . $publicCode . "\n\n"
        . "You can invite people and manage household access.\n\n"
        . "HomeLedger - Home money, clearly.\n"
        . mail_footer_host() . "\n";
}

function new_owner_mail_html(string $householdName, string $publicCode): string
{
    $safeHousehold = e($householdName);
    $safeCode = e($publicCode);
    $appUrl = e(rtrim(app_public_url(), '/'));
    $extra = '<tr>
<td style="padding:16px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:13px;line-height:1.5;">
Household ID<br>
<strong style="color:#f5f4ef;letter-spacing:0.08em;">' . $safeCode . '</strong>
</td>
</tr>
<tr>
<td style="padding:12px 32px 32px;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:13px;line-height:1.5;">
You can invite people and manage household access.
</td>
</tr>';

    return account_mail_html(
        'You are now the owner',
        'Household',
        'You are now the owner of ' . $householdName . ' on HomeLedger.',
        'You are now the owner of <strong style="color:#f5f4ef;">' . $safeHousehold . '</strong> on HomeLedger.',
        $extra,
        'Open HomeLedger',
        $appUrl === '' ? '' : $appUrl . '/'
    );
}

function send_account_deleted_mail(string $to, bool $householdDeleted): string
{
    $subject = 'Your HomeLedger account was deleted';
    $body = account_deleted_mail_plain($householdDeleted);
    $html = account_deleted_mail_html($householdDeleted);

    return send_account_notice_mail($to, $subject, $body, $html, 'account deletion');
}

function send_new_owner_mail(string $to, string $householdName, string $publicCode): string
{
    $subject = 'You are now the HomeLedger household owner';
    $body = new_owner_mail_plain($householdName, $publicCode);
    $html = new_owner_mail_html($householdName, $publicCode);

    return send_account_notice_mail($to, $subject, $body, $html, 'new owner');
}

function send_account_notice_mail(
    string $to,
    string $subject,
    string $body,
    string $html,
    string $kind
): string {
    if (!mail_is_configured()) {
        return 'unconfigured';
    }

    try {
        send_smtp_message($to, $subject, $body, $html);

        return 'sent';
    } catch (Throwable $exception) {
        error_log('HomeLedger ' . $kind . ' mail failed: ' . $exception->getMessage());

        return 'failed';
    }
}

function assert_household_id_confirmation(string $typed, string $expected): void
{
    $key = household_public_code_key($typed);
    if ($key === '' || (ctype_digit($key) && strlen($key) < HOUSEHOLD_PUBLIC_CODE_LENGTH)) {
        throw new InvalidArgumentException('Type the household ID shown in your profile, not a number.');
    }
    if (!household_public_codes_equal($expected, $typed)) {
        throw new InvalidArgumentException('Type the household ID exactly to confirm deletion.');
    }
}

function household_member_count(PDO $pdo, int $householdId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE household_id = ?');
    $stmt->execute([$householdId]);

    return (int) $stmt->fetchColumn();
}

/** @return array<string, mixed>|null */
function household_member_row(PDO $pdo, int $householdId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, login, display_name, household_id, password_hash
         FROM users
         WHERE id = ? AND household_id = ?'
    );
    $stmt->execute([$userId, $householdId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function wipe_household_dependent_rows(PDO $pdo, int $householdId): void
{
    $pdo->prepare('DELETE FROM household_invites WHERE household_id = ?')->execute([$householdId]);
    $pdo->prepare('DELETE FROM transactions WHERE household_id = ?')->execute([$householdId]);
    $pdo->prepare('DELETE FROM recurring_entries WHERE household_id = ?')->execute([$householdId]);
    $pdo->prepare('DELETE FROM categories WHERE household_id = ?')->execute([$householdId]);
}

function reassign_invites_from_user(PDO $pdo, int $householdId, int $fromUserId, int $toUserId): void
{
    $stmt = $pdo->prepare(
        'UPDATE household_invites
         SET invited_by_user_id = ?
         WHERE household_id = ? AND invited_by_user_id = ?'
    );
    $stmt->execute([$toUserId, $householdId, $fromUserId]);
}

/**
 * Permanently deletes the signed-in user. Only that user is removed.
 * $transferUserId is used only as the new owner when required.
 */
function delete_current_user_account(
    string $password,
    string $typedHouseholdId,
    ?int $transferUserId = null,
    bool $sendMail = true
): void {
    $user = current_user();
    if ($user === null) {
        throw new RuntimeException('Sign in to delete your account.');
    }

    $userId = (int) $user['id'];
    $householdId = (int) $user['household_id'];
    $pdo = db();
    $account = household_member_row($pdo, $householdId, $userId);
    if ($account === null || !password_verify($password, (string) $account['password_hash'])) {
        throw new InvalidArgumentException('The current password is not correct.');
    }

    $expectedCode = (string) ($user['household_public_code'] ?? '');
    assert_household_id_confirmation($typedHouseholdId, $expectedCode);

    $memberCount = household_member_count($pdo, $householdId);
    $isOwner = $userId === household_owner_user_id($householdId);
    $newOwner = null;

    if ($isOwner && $memberCount > 1) {
        $transferId = $transferUserId ?? 0;
        if ($transferId < 1 || $transferId === $userId) {
            throw new InvalidArgumentException('Choose who will become the household owner.');
        }
        $newOwner = household_member_row($pdo, $householdId, $transferId);
        if ($newOwner === null) {
            throw new InvalidArgumentException('Choose a current member of this household.');
        }
    }

    $householdDeleted = $isOwner && $memberCount === 1;
    $deletedEmail = (string) $account['login'];
    $householdName = (string) ($user['household_name'] ?? 'this household');
    $publicCode = $expectedCode;

    if ($sendMail) {
        send_account_deleted_mail($deletedEmail, $householdDeleted);
        if (is_array($newOwner)) {
            send_new_owner_mail((string) $newOwner['login'], $householdName, $publicCode);
        }
    }

    $pdo->beginTransaction();
    try {
        $stillThere = household_member_row($pdo, $householdId, $userId);
        if ($stillThere === null) {
            throw new InvalidArgumentException('That account could not be deleted.');
        }
        $liveCount = household_member_count($pdo, $householdId);
        $liveOwner = household_owner_user_id($householdId) === $userId;

        if ($liveOwner && $liveCount > 1) {
            if (!is_array($newOwner)) {
                throw new InvalidArgumentException('Choose who will become the household owner.');
            }
            $targetId = (int) $newOwner['id'];
            $target = household_member_row($pdo, $householdId, $targetId);
            if ($target === null || $targetId === $userId) {
                throw new InvalidArgumentException('Choose a current member of this household.');
            }
            $transfer = $pdo->prepare(
                'UPDATE households SET owner_user_id = ?, state_version = state_version + 1 WHERE id = ?'
            );
            $transfer->execute([$targetId, $householdId]);
            reassign_invites_from_user($pdo, $householdId, $userId, $targetId);
            $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ? AND household_id = ?');
            $deleteUser->execute([$userId, $householdId]);
        } elseif ($liveOwner && $liveCount === 1) {
            $clearOwner = $pdo->prepare('UPDATE households SET owner_user_id = NULL WHERE id = ?');
            $clearOwner->execute([$householdId]);
            wipe_household_dependent_rows($pdo, $householdId);
            $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ? AND household_id = ?');
            $deleteUser->execute([$userId, $householdId]);
            $deleteHousehold = $pdo->prepare('DELETE FROM households WHERE id = ?');
            $deleteHousehold->execute([$householdId]);
        } else {
            $remainingOwner = household_owner_user_id($householdId);
            if ($remainingOwner > 0 && $remainingOwner !== $userId) {
                reassign_invites_from_user($pdo, $householdId, $userId, $remainingOwner);
            } else {
                $pdo->prepare('DELETE FROM household_invites WHERE invited_by_user_id = ? AND household_id = ?')
                    ->execute([$userId, $householdId]);
            }
            $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ? AND household_id = ?');
            $deleteUser->execute([$userId, $householdId]);
            bump_household_state($pdo, $householdId);
        }

        if ($deleteUser->rowCount() !== 1) {
            throw new InvalidArgumentException('That account could not be deleted.');
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
