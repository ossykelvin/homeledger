<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;

function invite_mail_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $message . "\n");
}

$household = 'Oak & Ash <test>';
$url = 'https://example.com/?page=register&invite=token-one';
$expires = new DateTimeImmutable('2026-09-05 20:00:00', new DateTimeZone('Europe/London'));
$invitedBy = 'Ada';

$plain = invite_mail_plain($household, $url, $expires, $invitedBy);
$html = invite_mail_html($household, $url, $expires, $invitedBy);
$mime = compose_mail_mime($plain, $html, 'HomeLedgerTestBoundary');
$payload = implode("\r\n", array_merge(
    ['MIME-Version: 1.0'],
    $mime['headers'],
    ['', smtp_dot_stuff($mime['body'])]
));

invite_mail_assert(str_contains($plain, $url), 'Plaintext should include the join URL.');
invite_mail_assert(str_contains($plain, 'Ada invited you to join'), 'Plaintext should name the inviter.');
invite_mail_assert(str_contains($plain, '24 hours'), 'Plaintext should mention 24-hour expiry.');
invite_mail_assert(!str_contains($plain, '—'), 'Plaintext should not use an em dash.');

invite_mail_assert(str_contains($html, '#080b0f'), 'HTML should use the Kokoszone ink background.');
invite_mail_assert(str_contains($html, '#c7f36b'), 'HTML should use the lime accent.');
invite_mail_assert(str_contains($html, '#171c23'), 'HTML should use the raised surface card.');
invite_mail_assert(str_contains($html, 'Join household'), 'HTML should include the join button label.');
invite_mail_assert(str_contains($html, e($url)), 'HTML should include the escaped join URL.');
invite_mail_assert(str_contains($html, 'Ada'), 'HTML should name the inviter.');
invite_mail_assert(str_contains($html, 'Oak &amp; Ash &lt;test&gt;'), 'HTML should escape the household name.');
invite_mail_assert(!str_contains($html, 'localhost'), 'HTML should not point at localhost brand assets.');
invite_mail_assert(!str_contains($html, '—'), 'HTML should not use an em dash.');

invite_mail_assert(
    str_contains($payload, 'Content-Type: multipart/alternative; boundary="HomeLedgerTestBoundary"'),
    'MIME should declare multipart/alternative with the boundary.'
);
invite_mail_assert(str_contains($payload, 'Content-Type: text/plain; charset=UTF-8'), 'MIME should include a text/plain part.');
invite_mail_assert(str_contains($payload, 'Content-Type: text/html; charset=UTF-8'), 'MIME should include a text/html part.');
invite_mail_assert(str_contains($payload, 'Content-Transfer-Encoding: quoted-printable'), 'MIME parts should use quoted-printable.');
invite_mail_assert(str_contains($payload, '--HomeLedgerTestBoundary--'), 'MIME should close the boundary.');
invite_mail_assert(
    str_contains(quoted_printable_decode($payload), $url),
    'Decoded MIME should still include the join URL.'
);

global $config;
$previousAppUrl = $config['app']['url'] ?? '';
$config['app']['url'] = 'http://localhost:8080';
$localHtml = invite_mail_html($household, 'http://localhost:8080/?page=register&invite=abc', $expires);
invite_mail_assert(
    !str_contains($localHtml, 'assets/brand/'),
    'Localhost APP_URL should not embed remote brand image URLs.'
);

$config['app']['url'] = 'https://homeledger.koptechnology.co.uk';
$publicHtml = invite_mail_html($household, 'https://homeledger.koptechnology.co.uk/?page=register&invite=abc', $expires);
invite_mail_assert(
    mail_public_asset_base() === 'https://homeledger.koptechnology.co.uk',
    'Public HTTPS APP_URL should be treated as a public asset base.'
);
invite_mail_assert(
    invite_register_url('abc') === 'https://homeledger.koptechnology.co.uk/?page=register&invite=abc',
    'Invite register URLs should use the public APP_URL origin.'
);
invite_mail_assert(
    str_contains($publicHtml, 'https://homeledger.koptechnology.co.uk/assets/brand/logo-dark.png'),
    'Public APP_URL should embed the dark brand logo.'
);
$config['app']['url'] = $previousAppUrl;

if ($failures > 0) {
    fwrite(STDERR, "Invite mail tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Invite mail tests passed\n");
