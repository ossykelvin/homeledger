<?php

declare(strict_types=1);

final class SmtpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $smtpCode = 0,
    ) {
        parent::__construct($message);
    }
}

function mail_password(): string
{
    return trim((string) config('mail.password', ''));
}

function mail_is_configured(): bool
{
    $host = trim((string) config('mail.host', ''));
    $username = trim((string) config('mail.username', ''));

    return $host !== '' && $username !== '' && mail_password() !== '';
}

function smtp_user_reason(Throwable $exception): string
{
    $code = $exception instanceof SmtpException ? $exception->smtpCode : 0;
    $message = $exception->getMessage();

    if ($code === 535 || stripos($message, 'authentication failed') !== false) {
        return 'Brevo rejected the SMTP login. Use the current SMTP key from Settings → SMTP & API, not an API key.';
    }
    if ($code === 550 || preg_match('/unverified|sender/i', $message) === 1) {
        return 'Brevo rejected the sender. Verify noreply@kokoszone.com or the kokoszone.com domain in Brevo.';
    }

    return 'The mail server could not send the email.';
}

function mail_from_address(): string
{
    $from = trim((string) config('mail.from', ''));
    if ($from !== '') {
        return $from;
    }

    return trim((string) config('mail.username', ''));
}

function mail_from_name(): string
{
    $name = trim((string) config('mail.from_name', ''));

    return $name !== '' ? $name : 'HomeLedger';
}

function mail_from_header(string $from): string
{
    return encode_mail_header(mail_from_name()) . ' <' . $from . '>';
}

function encode_mail_header(string $value): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
        return $value;
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_dot_stuff(string $body): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $normalized);
    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }
    unset($line);

    return implode("\r\n", $lines);
}

function encode_mail_quoted_printable(string $value): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $value);
    $encoded = [];
    foreach (explode("\n", $normalized) as $line) {
        $chunk = str_replace(["\r\n", "\r"], "\n", quoted_printable_encode($line));
        $encoded[] = rtrim($chunk, "\n");
    }

    return str_replace("\n", "\r\n", implode("\n", $encoded));
}

function mail_public_asset_base(): string
{
    $base = rtrim(trim((string) config('app.url', '')), '/');
    if ($base === '') {
        return '';
    }

    $parts = parse_url($base);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }
    if (
        $host === 'localhost'
        || $host === '127.0.0.1'
        || $host === '::1'
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.local')
    ) {
        return '';
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($public === false) {
            return '';
        }
    }

    return $base;
}

/**
 * @return array{headers: list<string>, body: string, boundary: string}
 */
function compose_mail_mime(string $plain, string $html = '', string $boundary = ''): array
{
    if ($html === '') {
        return [
            'headers' => [
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: quoted-printable',
            ],
            'body' => encode_mail_quoted_printable($plain),
            'boundary' => '',
        ];
    }

    if ($boundary === '') {
        $boundary = '_HomeLedger_' . bin2hex(random_bytes(12));
    }

    $parts = [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        encode_mail_quoted_printable($plain),
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        encode_mail_quoted_printable($html),
        '--' . $boundary . '--',
        '',
    ];

    return [
        'headers' => [
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ],
        'body' => implode("\r\n", $parts),
        'boundary' => $boundary,
    ];
}

function build_smtp_data_payload(string $to, string $from, string $subject, string $plain, string $html = ''): string
{
    $mime = compose_mail_mime($plain, $html);

    return implode("\r\n", array_merge(
        [
            'From: ' . mail_from_header($from),
            'To: <' . $to . '>',
            'Subject: ' . encode_mail_header($subject),
            'MIME-Version: 1.0',
        ],
        $mime['headers'],
        [
            '',
            smtp_dot_stuff($mime['body']),
        ]
    ));
}

/** @param resource $socket */
function smtp_read($socket): string
{
    $response = '';
    while (($line = fgets($socket, 512)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    if ($response === '') {
        throw new RuntimeException('The mail server closed the connection.');
    }

    return $response;
}

/**
 * @param resource $socket
 * @param list<int> $codes
 */
function smtp_expect($socket, array $codes): string
{
    $response = smtp_read($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new SmtpException('Mail server replied ' . trim($response), $code);
    }

    return $response;
}

/**
 * @param resource $socket
 * @param list<int> $codes
 */
function smtp_command($socket, string $command, array $codes, bool $secret = false): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Could not write to the mail server.');
    }

    try {
        return smtp_expect($socket, $codes);
    } catch (SmtpException $exception) {
        if ($secret) {
            throw new SmtpException('Mail authentication failed (' . $exception->smtpCode . ').', $exception->smtpCode);
        }
        throw $exception;
    }
}

function send_smtp_message(string $to, string $subject, string $body, string $html = ''): void
{
    if (!mail_is_configured()) {
        throw new RuntimeException('Mail is not configured.');
    }
    if (!valid_login_email($to)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }

    $host = trim((string) config('mail.host', ''));
    $port = (int) config('mail.port', 587);
    if ($port < 1 || $port > 65535) {
        $port = 587;
    }
    $username = trim((string) config('mail.username', ''));
    $password = mail_password();
    $from = mail_from_address();
    if (!valid_login_email($from)) {
        throw new RuntimeException('MAIL_FROM is not a valid email address.');
    }
    $encryption = strtolower(trim((string) config('mail.encryption', 'tls')));

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
        ],
    ]);
    $remote = 'tcp://' . $host . ':' . $port;
    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if ($socket === false) {
        throw new RuntimeException('Could not connect to the mail server (' . $errno . ').');
    }

    stream_set_timeout($socket, 20);

    try {
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO homeledger', [250]);

        if ($encryption === 'tls' || $encryption === 'starttls') {
            smtp_command($socket, 'STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
            }
            $secured = @stream_socket_enable_crypto($socket, true, $crypto);
            if ($secured !== true) {
                throw new RuntimeException('Could not start TLS with the mail server.');
            }
            smtp_command($socket, 'EHLO homeledger', [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334], true);
        smtp_command($socket, base64_encode($password), [235], true);
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $payload = build_smtp_data_payload($to, $from, $subject, $body, $html);
        if (fwrite($socket, $payload . "\r\n.\r\n") === false) {
            throw new RuntimeException('Could not send the mail data.');
        }
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}
