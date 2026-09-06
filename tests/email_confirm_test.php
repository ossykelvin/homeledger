<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;

function email_confirm_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $message . "\n");
}

$rawToken = 'confirm-token-one';
$url = 'https://example.com/?page=confirm&token=confirm-token-one';
$expires = new DateTimeImmutable('2026-09-05 20:00:00', new DateTimeZone('Europe/London'));

email_confirm_assert(
    hash_email_confirm_token($rawToken) === hash('sha256', $rawToken),
    'Confirm tokens should be stored as SHA-256 hex hashes.'
);
email_confirm_assert(
    strlen(hash_email_confirm_token($rawToken)) === 64,
    'Confirm token hashes should be 64-character hex strings.'
);

$plain = email_confirm_mail_plain($url, $expires);
$html = email_confirm_mail_html($url, $expires);
$mime = compose_mail_mime($plain, $html, 'HomeLedgerConfirmBoundary');
$payload = implode("\r\n", array_merge(
    ['MIME-Version: 1.0'],
    $mime['headers'],
    ['', smtp_dot_stuff($mime['body'])]
));

email_confirm_assert(str_contains($plain, $url), 'Plaintext should include the confirm URL.');
email_confirm_assert(str_contains($plain, 'Confirm this email to activate HomeLedger.'), 'Plaintext should ask the user to confirm.');
email_confirm_assert(str_contains($plain, '24 hours'), 'Plaintext should mention 24-hour expiry.');
email_confirm_assert(!str_contains($plain, '—'), 'Plaintext should not use an em dash.');

email_confirm_assert(str_contains($html, '#080b0f'), 'HTML should use the Kokoszone ink background.');
email_confirm_assert(str_contains($html, '#c7f36b'), 'HTML should use the lime accent.');
email_confirm_assert(str_contains($html, '#171c23'), 'HTML should use the raised surface card.');
email_confirm_assert(str_contains($html, 'Confirm email'), 'HTML should include the confirm button label.');
email_confirm_assert(str_contains($html, e($url)), 'HTML should include the escaped confirm URL.');
email_confirm_assert(!str_contains($html, 'localhost'), 'HTML should not point at localhost brand assets.');
email_confirm_assert(!str_contains($html, '—'), 'HTML should not use an em dash.');

email_confirm_assert(
    str_contains($payload, 'Content-Type: multipart/alternative; boundary="HomeLedgerConfirmBoundary"'),
    'MIME should declare multipart/alternative with the boundary.'
);
email_confirm_assert(str_contains($payload, 'Content-Type: text/plain; charset=UTF-8'), 'MIME should include a text/plain part.');
email_confirm_assert(str_contains($payload, 'Content-Type: text/html; charset=UTF-8'), 'MIME should include a text/html part.');
email_confirm_assert(
    str_contains(quoted_printable_decode($payload), $url),
    'Decoded MIME should still include the confirm URL.'
);

global $config;
$previousAppUrl = $config['app']['url'] ?? '';
$config['app']['url'] = 'http://localhost:8080';
$localHtml = email_confirm_mail_html('http://localhost:8080/?page=confirm&token=abc', $expires);
email_confirm_assert(
    !str_contains($localHtml, 'assets/brand/'),
    'Localhost APP_URL should not embed remote brand image URLs.'
);
email_confirm_assert(
    email_confirm_url('abc') === 'http://localhost:8080/?page=confirm&token=abc',
    'Confirm URLs should use the configured APP_URL origin.'
);

$config['app']['url'] = 'https://homeledger.koptechnology.co.uk';
$publicHtml = email_confirm_mail_html('https://homeledger.koptechnology.co.uk/?page=confirm&token=abc', $expires);
email_confirm_assert(
    email_confirm_url('abc') === 'https://homeledger.koptechnology.co.uk/?page=confirm&token=abc',
    'Confirm URLs should use the public APP_URL origin.'
);
email_confirm_assert(
    str_contains($publicHtml, 'https://homeledger.koptechnology.co.uk/assets/brand/logo-dark.png'),
    'Public APP_URL should embed the dark brand logo.'
);
$config['app']['url'] = $previousAppUrl;

$migration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/007_email_confirmation.sql');
email_confirm_assert(
    str_contains($migration, 'email_verified_at'),
    'Migration should add email_verified_at.'
);
email_confirm_assert(
    str_contains($migration, 'email_confirm_token_hash'),
    'Migration should add a hashed confirm token column.'
);
email_confirm_assert(
    preg_match('/UPDATE\s+users\s+SET\s+email_verified_at\s*=\s*NOW\(\)/i', $migration) === 1,
    'Migration should mark existing users as verified so nobody is locked out.'
);

$schema = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');
email_confirm_assert(
    str_contains($schema, 'email_verified_at DATETIME NULL'),
    'Fresh schema should include email_verified_at.'
);

$checkEmail = (string) file_get_contents(dirname(__DIR__) . '/templates/pages/check-email.php');
email_confirm_assert(
    !str_contains($checkEmail, 'page=confirm'),
    'Check-email page should not include a confirm URL.'
);
email_confirm_assert(
    !str_contains($checkEmail, 'token='),
    'Check-email page should not include a confirm token.'
);
email_confirm_assert(
    !str_contains($checkEmail, 'email_confirm_pending_link'),
    'Check-email page should not render a pending confirm URL.'
);
email_confirm_assert(
    str_contains($checkEmail, 'Check your inbox'),
    'Check-email page should tell the user to check their inbox.'
);
email_confirm_assert(
    str_contains($checkEmail, '24 hours'),
    'Check-email page should mention 24-hour expiry.'
);
email_confirm_assert(
    str_contains($checkEmail, 'Resend confirmation'),
    'Check-email page should offer resend.'
);
email_confirm_assert(!str_contains($checkEmail, '—'), 'Check-email page should not use an em dash.');

$confirmSrc = (string) file_get_contents(dirname(__DIR__) . '/app/EmailConfirm.php');
email_confirm_assert(
    !str_contains($confirmSrc, 'Copy the link below'),
    'Signup confirm copy should not tell the user to copy a URL.'
);
email_confirm_assert(
    preg_match("/\\\$\_SESSION\\['email_confirm_link'\\]\\s*=/", $confirmSrc) !== 1,
    'Confirm URL should not be stored in the session for UI display.'
);
email_confirm_assert(
    str_contains($confirmSrc, 'We could not send the confirmation email. Try Resend confirmation.'),
    'Failed sends should ask the user to try Resend without showing a URL.'
);
email_confirm_assert(
    str_contains($html, $url) || str_contains($html, e($url)),
    'Confirmation email HTML should still include the confirm URL.'
);

if ($failures > 0) {
    fwrite(STDERR, "Email confirm tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Email confirm tests passed\n");
