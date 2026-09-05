<?php

declare(strict_types=1);

const EMAIL_CONFIRM_TTL_HOURS = 24;
const EMAIL_CONFIRM_RESEND_COOLDOWN_SECONDS = 60;

function hash_email_confirm_token(string $rawToken): string
{
    return hash('sha256', $rawToken);
}

function email_confirm_url(string $rawToken): string
{
    return app_public_url() . '/?page=confirm&token=' . rawurlencode($rawToken);
}

/** @return array{url: string, email: string}|null */
function email_confirm_pending_link(): ?array
{
    $payload = $_SESSION['email_confirm_link'] ?? null;
    if (!is_array($payload) || empty($payload['url']) || !is_string($payload['url'])) {
        return null;
    }

    return [
        'url' => $payload['url'],
        'email' => is_string($payload['email'] ?? null) ? $payload['email'] : '',
    ];
}

function pending_email_confirm_login(): string
{
    $login = $_SESSION['pending_email_confirm'] ?? '';

    return is_string($login) ? normalize_login_email($login) : '';
}

function clear_email_confirm_pending(): void
{
    unset(
        $_SESSION['email_confirm_link'],
        $_SESSION['pending_email_confirm'],
        $_SESSION['email_confirm_mail_status'],
        $_SESSION['email_confirm_mail_error']
    );
}

function email_confirm_mail_plain(string $url, DateTimeImmutable $expires): string
{
    return "Confirm this email to activate HomeLedger.\n\n"
        . "Open this link to confirm your email:\n"
        . $url . "\n\n"
        . 'This link expires in 24 hours (by ' . $expires->format('j M Y H:i T') . ").\n\n"
        . "If you did not create a HomeLedger household, you can ignore this.\n\n"
        . "HomeLedger - Home money, clearly.\n";
}

function email_confirm_mail_html(string $url, DateTimeImmutable $expires): string
{
    $safeUrl = e($url);
    $safeExpires = e($expires->format('j M Y H:i T'));
    $preheader = e('Confirm this email to activate HomeLedger. This link expires in 24 hours.');

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
<title>Confirm your email for HomeLedger</title>
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
Email confirmation
</td>
</tr>
<tr>
<td style="padding:8px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#f5f4ef;font-size:28px;font-weight:700;letter-spacing:-0.04em;line-height:1.15;">
Confirm your email
</td>
</tr>
<tr>
<td style="padding:16px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:16px;line-height:1.5;">
Confirm this email to activate HomeLedger.
</td>
</tr>
<tr>
<td align="center" style="padding:28px 32px 8px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
<tr>
<td align="center" bgcolor="#c7f36b" style="border-radius:999px;background-color:#c7f36b;">
<a href="' . $safeUrl . '" style="display:inline-block;padding:12px 22px;border:1px solid #c7f36b;border-radius:999px;background-color:#c7f36b;color:#080b0f;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;text-decoration:none;">Confirm email</a>
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
If you did not create a HomeLedger household, you can ignore this.
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

function send_email_confirm_mail(string $to, string $url, DateTimeImmutable $expires): string
{
    $subject = 'Confirm your email for HomeLedger';
    $body = email_confirm_mail_plain($url, $expires);
    $html = email_confirm_mail_html($url, $expires);

    if (!mail_is_configured()) {
        return 'unconfigured';
    }

    try {
        send_smtp_message($to, $subject, $body, $html);
        unset($_SESSION['email_confirm_mail_error']);

        return 'sent';
    } catch (Throwable $exception) {
        error_log('HomeLedger email confirmation mail failed: ' . $exception->getMessage());
        $_SESSION['email_confirm_mail_error'] = smtp_user_reason($exception);

        return 'failed';
    }
}

function publish_email_confirm_link(string $email, string $rawToken, DateTimeImmutable $expiresAt): void
{
    $url = email_confirm_url($rawToken);
    $_SESSION['email_confirm_link'] = ['url' => $url, 'email' => $email];
    $_SESSION['pending_email_confirm'] = $email;
    $_SESSION['email_confirm_mail_status'] = send_email_confirm_mail($email, $url, $expiresAt);
}

function pull_email_confirm_mail_status(): string
{
    $status = $_SESSION['email_confirm_mail_status'] ?? 'unconfigured';
    unset($_SESSION['email_confirm_mail_status']);

    return is_string($status) && in_array($status, ['sent', 'failed', 'unconfigured'], true)
        ? $status
        : 'unconfigured';
}

function pull_email_confirm_mail_error(): string
{
    $error = $_SESSION['email_confirm_mail_error'] ?? '';
    unset($_SESSION['email_confirm_mail_error']);

    return is_string($error) ? trim($error) : '';
}

function flash_after_email_confirm_send(bool $resent): void
{
    $status = pull_email_confirm_mail_status();
    if ($status === 'sent') {
        flash(
            'success',
            $resent
                ? 'Confirmation email sent. Check your inbox to activate HomeLedger. Copy the new link below if the email does not arrive.'
                : 'Check your inbox to confirm this email and activate HomeLedger. Copy the link below if the email does not arrive.'
        );

        return;
    }
    if ($status === 'failed') {
        $reason = pull_email_confirm_mail_error();
        $reasonSuffix = $reason !== '' ? ' ' . $reason : '';
        flash(
            'error',
            $resent
                ? 'A new confirmation link was created but the email failed to send.' . $reasonSuffix . ' Copy the link below.'
                : 'Your household was created but the confirmation email failed to send.' . $reasonSuffix . ' Copy the link below.'
        );

        return;
    }

    flash(
        'error',
        $resent
            ? 'A new confirmation link was created. Email is not configured. Copy the link below.'
            : 'Your household was created. Email is not configured. Copy the link below to confirm this email.'
    );
}

function issue_email_confirmation(int $userId, string $login): DateTimeImmutable
{
    $rawToken = bin2hex(random_bytes(32));
    $expiresAt = new DateTimeImmutable('+' . EMAIL_CONFIRM_TTL_HOURS . ' hours');
    $update = db()->prepare(
        'UPDATE users
         SET email_confirm_token_hash = ?, email_confirm_expires_at = ?
         WHERE id = ? AND email_verified_at IS NULL'
    );
    $update->execute([
        hash_email_confirm_token($rawToken),
        $expiresAt->format('Y-m-d H:i:s'),
        $userId,
    ]);
    if ($update->rowCount() !== 1) {
        throw new InvalidArgumentException('That confirmation email could not be sent.');
    }

    publish_email_confirm_link($login, $rawToken, $expiresAt);

    return $expiresAt;
}

function resend_email_confirmation(string $email): void
{
    prune_login_attempts();
    if (ip_login_blocked()) {
        throw new InvalidArgumentException(AUTH_LOCK_MESSAGE);
    }

    $login = normalize_login_email($email);
    if (!valid_login_email($login)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }

    $stmt = db()->prepare(
        'SELECT id, email_verified_at, email_confirm_expires_at
         FROM users
         WHERE login = ?'
    );
    $stmt->execute([$login]);
    $user = $stmt->fetch();
    if (!is_array($user)) {
        record_login_attempt($login);
        throw new InvalidArgumentException('If that email still needs confirming, we have sent a new link.');
    }
    if (!empty($user['email_verified_at'])) {
        throw new InvalidArgumentException('This email is already confirmed. Sign in.');
    }

    $expires = strtotime((string) ($user['email_confirm_expires_at'] ?? ''));
    $freshThreshold = time() + (EMAIL_CONFIRM_TTL_HOURS * 3600) - EMAIL_CONFIRM_RESEND_COOLDOWN_SECONDS;
    if ($expires !== false && $expires > $freshThreshold) {
        throw new InvalidArgumentException('Wait a minute before sending this confirmation email again.');
    }

    issue_email_confirmation((int) $user['id'], $login);
}

function confirm_email_with_token(string $rawToken): int
{
    $rawToken = trim($rawToken);
    if ($rawToken === '' || text_length($rawToken) > 128) {
        throw new InvalidArgumentException(
            'This confirmation link is invalid or has expired. Sign in or request a new email.'
        );
    }

    $hash = hash_email_confirm_token($rawToken);
    $stmt = db()->prepare(
        'SELECT id, email_verified_at, email_confirm_token_hash, email_confirm_expires_at
         FROM users
         WHERE email_confirm_token_hash = ?'
    );
    $stmt->execute([$hash]);
    $user = $stmt->fetch();
    if (
        !is_array($user)
        || !hash_equals((string) $user['email_confirm_token_hash'], $hash)
    ) {
        throw new InvalidArgumentException(
            'This confirmation link is invalid or has expired. Sign in or request a new email.'
        );
    }

    $expires = strtotime((string) $user['email_confirm_expires_at']);
    if ($expires === false || $expires <= time()) {
        throw new InvalidArgumentException(
            'This confirmation link is invalid or has expired. Sign in or request a new email.'
        );
    }

    $update = db()->prepare(
        'UPDATE users
         SET email_verified_at = COALESCE(email_verified_at, NOW()),
             email_confirm_token_hash = NULL,
             email_confirm_expires_at = NULL
         WHERE id = ? AND email_confirm_token_hash = ? AND email_confirm_expires_at > NOW()'
    );
    $update->execute([(int) $user['id'], $hash]);
    if ($update->rowCount() !== 1 && empty($user['email_verified_at'])) {
        throw new InvalidArgumentException(
            'This confirmation link is invalid or has expired. Sign in or request a new email.'
        );
    }

    return (int) $user['id'];
}

function complete_email_confirmation_from_query(): never
{
    $rawToken = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';

    try {
        $userId = confirm_email_with_token($rawToken);
        clear_email_confirm_pending();
        establish_user_session($userId);
        flash('success', 'Your email is confirmed. HomeLedger is ready.');
        redirect('dashboard');
    } catch (InvalidArgumentException $exception) {
        flash('error', $exception->getMessage());
        redirect('login');
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        flash('error', 'This confirmation link could not be used. Sign in or request a new email.');
        redirect('login');
    }
}
