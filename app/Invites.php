<?php

declare(strict_types=1);

const INVITE_TTL_HOURS = 24;
const INVITE_DAILY_LIMIT = 10;
const INVITE_RESEND_COOLDOWN_SECONDS = 60;

function app_public_url(): string
{
    $configured = trim((string) config('app.url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

function hash_invite_token(string $rawToken): string
{
    return hash('sha256', $rawToken);
}

function invite_register_url(string $rawToken): string
{
    return app_public_url() . '/?page=register&invite=' . rawurlencode($rawToken);
}

function pull_invite_link(): ?array
{
    $payload = $_SESSION['invite_link'] ?? null;
    unset($_SESSION['invite_link']);

    if (!is_array($payload) || empty($payload['url']) || !is_string($payload['url'])) {
        return null;
    }

    return [
        'url' => $payload['url'],
        'email' => is_string($payload['email'] ?? null) ? $payload['email'] : '',
    ];
}

/** @return array<string, mixed>|null */
function find_invite_by_raw_token(string $rawToken): ?array
{
    $rawToken = trim($rawToken);
    if ($rawToken === '' || text_length($rawToken) > 128) {
        return null;
    }

    $hash = hash_invite_token($rawToken);
    $stmt = db()->prepare(
        'SELECT i.id, i.household_id, i.invited_by_user_id, i.email, i.token_hash,
                i.expires_at, i.accepted_at, i.created_at, h.name AS household_name
         FROM household_invites i
         JOIN households h ON h.id = i.household_id
         WHERE i.token_hash = ?'
    );
    $stmt->execute([$hash]);
    $invite = $stmt->fetch();
    if (!is_array($invite) || !hash_equals((string) $invite['token_hash'], $hash)) {
        return null;
    }

    return $invite;
}

/** @param array<string, mixed> $invite */
function invite_is_open(array $invite): bool
{
    if (!empty($invite['accepted_at'])) {
        return false;
    }

    $expires = strtotime((string) $invite['expires_at']);

    return $expires !== false && $expires > time();
}

/** @param array<string, mixed> $invite */
function invite_status_label(array $invite): string
{
    if (!empty($invite['accepted_at'])) {
        return 'Accepted';
    }
    if (!invite_is_open($invite)) {
        return 'Expired';
    }

    return 'Pending';
}

/** @return list<array<string, mixed>> */
function household_invites_for_current(): array
{
    $stmt = db()->prepare(
        'SELECT id, email, expires_at, accepted_at, created_at
         FROM household_invites
         WHERE household_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 50'
    );
    $stmt->execute([current_household_id()]);

    return $stmt->fetchAll() ?: [];
}

function household_invite_count_today(int $householdId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM household_invites
         WHERE household_id = ? AND created_at >= (NOW() - INTERVAL 1 DAY)'
    );
    $stmt->execute([$householdId]);

    return (int) $stmt->fetchColumn();
}

function invite_mail_intro(string $householdName, string $invitedBy = ''): string
{
    if ($invitedBy !== '') {
        return $invitedBy . ' invited you to join ' . $householdName . ' on HomeLedger.';
    }

    return 'You have been invited to join ' . $householdName . ' on HomeLedger.';
}

function invite_mail_plain(string $householdName, string $url, DateTimeImmutable $expires, string $invitedBy = ''): string
{
    return invite_mail_intro($householdName, $invitedBy) . "\n\n"
        . "Open this link to create your account:\n"
        . $url . "\n\n"
        . 'This link expires in 24 hours (by ' . $expires->format('j M Y H:i T') . ").\n\n"
        . "If you were not expecting this, you can ignore it.\n\n"
        . "HomeLedger - Home money, clearly.\n";
}

function invite_mail_html(string $householdName, string $url, DateTimeImmutable $expires, string $invitedBy = ''): string
{
    $safeHousehold = e($householdName);
    $safeUrl = e($url);
    $safeExpires = e($expires->format('j M Y H:i T'));
    $safeInviter = e($invitedBy);
    $preheader = e('Join ' . $householdName . ' on HomeLedger. This link expires in 24 hours.');

    $intro = $invitedBy !== ''
        ? $safeInviter . ' invited you to join <strong style="color:#f5f4ef;">'
            . $safeHousehold . '</strong> on HomeLedger.'
        : 'You have been invited to join <strong style="color:#f5f4ef;">'
            . $safeHousehold . '</strong> on HomeLedger.';

    $logoSrc = '';
    $assetBase = mail_public_asset_base();
    if ($assetBase !== '') {
        $logoSrc = $assetBase . '/assets/brand/logo-dark.png';
    }

    $brandMark = $logoSrc !== ''
        ? '<img src="' . e($logoSrc) . '" width="180" alt="HomeLedger" style="display:block;width:180px;max-width:180px;height:auto;border:0;">'
        : '<table role="presentation" cellpadding="0" cellspacing="0" border="0">'
            . '<tr>'
            . '<td style="width:28px;height:28px;border-radius:8px;background-color:#c7f36b;color:#080b0f;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:800;line-height:28px;text-align:center;">h</td>'
            . '<td style="padding-left:10px;color:#f5f4ef;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:700;letter-spacing:-0.03em;line-height:1;">HomeLedger</td>'
            . '</tr></table>';

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title>You are invited to HomeLedger</title>
</head>
<body style="margin:0;padding:0;background-color:#080b0f;color:#f5f4ef;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#080b0f;">' . $preheader . '</div>
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
Household invite
</td>
</tr>
<tr>
<td style="padding:8px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#f5f4ef;font-size:28px;font-weight:700;letter-spacing:-0.04em;line-height:1.15;">
You are invited
</td>
</tr>
<tr>
<td style="padding:16px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:16px;line-height:1.5;">
' . $intro . '
</td>
</tr>
<tr>
<td align="center" style="padding:28px 32px 8px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
<tr>
<td align="center" bgcolor="#c7f36b" style="border-radius:999px;background-color:#c7f36b;">
<a href="' . $safeUrl . '" style="display:inline-block;padding:12px 22px;border:1px solid #c7f36b;border-radius:999px;background-color:#c7f36b;color:#080b0f;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;text-decoration:none;">Join household</a>
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="padding:12px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:13px;line-height:1.5;word-break:break-all;">
If the button does not work, copy this link:<br>
<a href="' . $safeUrl . '" style="color:#c7f36b;text-decoration:underline;">' . $safeUrl . '</a>
</td>
</tr>
<tr>
<td style="padding:24px 32px 8px;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:13px;line-height:1.5;">
This link expires in 24 hours (by ' . $safeExpires . ').
</td>
</tr>
<tr>
<td style="padding:0 32px 32px;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:13px;line-height:1.5;">
If you were not expecting this, you can ignore it.
</td>
</tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="width:560px;max-width:560px;">
<tr>
<td style="padding:18px 8px 0;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:12px;">
HomeLedger - Home money, clearly.
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

function send_invite_mail(string $to, string $householdName, string $url, DateTimeImmutable $expires, string $invitedBy = ''): string
{
    $subject = 'You are invited to HomeLedger';
    $body = invite_mail_plain($householdName, $url, $expires, $invitedBy);
    $html = invite_mail_html($householdName, $url, $expires, $invitedBy);

    if (!mail_is_configured()) {
        return 'unconfigured';
    }

    try {
        send_smtp_message($to, $subject, $body, $html);
        unset($_SESSION['invite_mail_error']);

        return 'sent';
    } catch (Throwable $exception) {
        error_log('HomeLedger invite mail failed: ' . $exception->getMessage());
        $_SESSION['invite_mail_error'] = smtp_user_reason($exception);

        return 'failed';
    }
}

function publish_invite_link(string $email, string $householdName, string $rawToken, DateTimeImmutable $expiresAt, string $invitedBy = ''): void
{
    $url = invite_register_url($rawToken);
    $_SESSION['invite_link'] = ['url' => $url, 'email' => $email];
    $_SESSION['invite_mail_status'] = send_invite_mail($email, $householdName, $url, $expiresAt, $invitedBy);
}

function pull_invite_mail_status(): string
{
    $status = $_SESSION['invite_mail_status'] ?? 'unconfigured';
    unset($_SESSION['invite_mail_status']);

    return is_string($status) && in_array($status, ['sent', 'failed', 'unconfigured'], true)
        ? $status
        : 'unconfigured';
}

function pull_invite_mail_error(): string
{
    $error = $_SESSION['invite_mail_error'] ?? '';
    unset($_SESSION['invite_mail_error']);

    return is_string($error) ? trim($error) : '';
}

function assert_invite_email_available(string $login, int $householdId): void
{
    $existing = db()->prepare('SELECT id, household_id FROM users WHERE login = ?');
    $existing->execute([$login]);
    $existingUser = $existing->fetch();
    if (!is_array($existingUser)) {
        return;
    }
    if ((int) $existingUser['household_id'] === $householdId) {
        throw new InvalidArgumentException('That person already has access to this household.');
    }
    throw new InvalidArgumentException('That email already has a HomeLedger account. They should sign in to their own household.');
}

function create_household_invite(string $email): string
{
    $user = current_user();
    if ($user === null) {
        throw new RuntimeException('Sign in to invite someone.');
    }
    assert_household_owner();

    $householdId = (int) $user['household_id'];
    $login = normalize_login_email($email);
    if (!valid_login_email($login)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (hash_equals(normalize_login_email((string) $user['login']), $login)) {
        throw new InvalidArgumentException('You already have access to this household.');
    }
    if (ip_login_blocked()) {
        throw new InvalidArgumentException(AUTH_LOCK_MESSAGE);
    }
    if (household_invite_count_today($householdId) >= INVITE_DAILY_LIMIT) {
        throw new InvalidArgumentException('This household has sent 10 invites in the last 24 hours. Try again later.');
    }

    assert_invite_email_available($login, $householdId);

    $rawToken = bin2hex(random_bytes(32));
    $expiresAt = new DateTimeImmutable('+' . INVITE_TTL_HOURS . ' hours');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $clear = $pdo->prepare(
            'DELETE FROM household_invites
             WHERE household_id = ? AND email = ? AND accepted_at IS NULL'
        );
        $clear->execute([$householdId, $login]);

        $insert = $pdo->prepare(
            'INSERT INTO household_invites
                (household_id, invited_by_user_id, email, token_hash, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $householdId,
            (int) $user['id'],
            $login,
            hash_invite_token($rawToken),
            $expiresAt->format('Y-m-d H:i:s'),
        ]);
        bump_household_state($pdo, $householdId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    publish_invite_link(
        $login,
        (string) $user['household_name'],
        $rawToken,
        $expiresAt,
        (string) $user['display_name']
    );

    return $rawToken;
}

function resend_household_invite(int $id): string
{
    $user = current_user();
    if ($user === null) {
        throw new RuntimeException('Sign in to invite someone.');
    }
    assert_household_owner();

    $householdId = (int) $user['household_id'];
    if (ip_login_blocked()) {
        throw new InvalidArgumentException(AUTH_LOCK_MESSAGE);
    }

    $stmt = db()->prepare(
        'SELECT id, email, accepted_at, expires_at
         FROM household_invites
         WHERE id = ? AND household_id = ?'
    );
    $stmt->execute([$id, $householdId]);
    $invite = $stmt->fetch();
    if (!is_array($invite)) {
        throw new InvalidArgumentException('That invite could not be resent.');
    }
    if (!empty($invite['accepted_at'])) {
        throw new InvalidArgumentException('That invite has already been accepted.');
    }

    $expires = strtotime((string) $invite['expires_at']);
    $freshThreshold = time() + (INVITE_TTL_HOURS * 3600) - INVITE_RESEND_COOLDOWN_SECONDS;
    if ($expires !== false && $expires > $freshThreshold) {
        throw new InvalidArgumentException('Wait a minute before sending this invite again.');
    }

    $login = normalize_login_email((string) $invite['email']);
    assert_invite_email_available($login, $householdId);

    $rawToken = bin2hex(random_bytes(32));
    $expiresAt = new DateTimeImmutable('+' . INVITE_TTL_HOURS . ' hours');
    $update = db()->prepare(
        'UPDATE household_invites
         SET token_hash = ?, expires_at = ?, invited_by_user_id = ?
         WHERE id = ? AND household_id = ? AND accepted_at IS NULL'
    );
    $update->execute([
        hash_invite_token($rawToken),
        $expiresAt->format('Y-m-d H:i:s'),
        (int) $user['id'],
        $id,
        $householdId,
    ]);
    if ($update->rowCount() !== 1) {
        throw new InvalidArgumentException('That invite could not be resent.');
    }
    bump_household_state(db(), $householdId);

    publish_invite_link(
        $login,
        (string) $user['household_name'],
        $rawToken,
        $expiresAt,
        (string) $user['display_name']
    );

    return $rawToken;
}

function revoke_household_invite(int $id): void
{
    assert_household_owner();
    $householdId = current_household_id();
    $stmt = db()->prepare(
        'DELETE FROM household_invites
         WHERE id = ? AND household_id = ? AND accepted_at IS NULL'
    );
    $stmt->execute([$id, $householdId]);
    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('That invite could not be cancelled.');
    }
    bump_household_state(db(), $householdId);
}
