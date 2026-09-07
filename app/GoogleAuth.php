<?php

declare(strict_types=1);

const GOOGLE_OAUTH_AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GOOGLE_OAUTH_USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
const GOOGLE_OAUTH_TIMEOUT_SECONDS = 20;

function google_oauth_client_id(): string
{
    return trim((string) config('google.client_id', ''));
}

function google_oauth_client_secret(): string
{
    return trim((string) config('google.client_secret', ''));
}

function google_oauth_is_configured(): bool
{
    return google_oauth_client_id() !== '' && google_oauth_client_secret() !== '';
}

function google_oauth_redirect_uri(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $isLocal = $host !== '' && (
        str_starts_with($host, 'localhost')
        || str_starts_with($host, '127.0.0.1')
    );
    if ($isLocal) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';

        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?page=google-callback';
    }

    return rtrim(app_public_url(), '/') . '/?page=google-callback';
}

function google_oauth_fail(string $message): never
{
    $invite = is_string($_SESSION['google_oauth_invite'] ?? null) ? trim((string) $_SESSION['google_oauth_invite']) : '';
    $next = safe_next_page(is_string($_SESSION['google_oauth_next'] ?? null) ? $_SESSION['google_oauth_next'] : null);
    unset(
        $_SESSION['google_oauth_state'],
        $_SESSION['google_oauth_redirect_uri'],
        $_SESSION['google_oauth_invite'],
        $_SESSION['google_oauth_next']
    );
    flash('error', $message);
    if ($invite !== '') {
        redirect('register', ['invite' => $invite]);
    }
    $query = [];
    if ($next !== 'dashboard') {
        $query['next'] = $next;
    }
    redirect('login', $query);
}

function start_google_oauth(): never
{
    if (!google_oauth_is_configured()) {
        flash('error', 'Google sign-in is not available.');
        redirect('login');
    }

    $invite = is_string($_GET['invite'] ?? null) ? trim($_GET['invite']) : '';
    $next = safe_next_page(is_string($_GET['next'] ?? null) ? $_GET['next'] : null);
    $state = bin2hex(random_bytes(32));
    $redirectUri = google_oauth_redirect_uri();

    $_SESSION['google_oauth_state'] = $state;
    $_SESSION['google_oauth_redirect_uri'] = $redirectUri;
    $_SESSION['google_oauth_next'] = $next;
    if ($invite !== '') {
        $_SESSION['google_oauth_invite'] = $invite;
    } else {
        unset($_SESSION['google_oauth_invite']);
    }

    $params = http_build_query([
        'client_id' => google_oauth_client_id(),
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ]);

    header('Location: ' . GOOGLE_OAUTH_AUTHORIZE_URL . '?' . $params);
    exit;
}

function complete_google_oauth_callback(): never
{
    $error = is_string($_GET['error'] ?? null) ? $_GET['error'] : '';
    if ($error === 'access_denied') {
        google_oauth_fail('Google sign-in was cancelled.');
    }
    if ($error !== '') {
        google_oauth_fail('Google sign-in did not complete. Try again.');
    }

    $state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
    $expected = is_string($_SESSION['google_oauth_state'] ?? null) ? $_SESSION['google_oauth_state'] : '';
    if ($expected === '' || $state === '' || !hash_equals($expected, $state)) {
        google_oauth_fail('This Google sign-in expired. Try again.');
    }

    $code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
    if ($code === '') {
        google_oauth_fail('Google sign-in did not complete. Try again.');
    }

    $redirectUri = is_string($_SESSION['google_oauth_redirect_uri'] ?? null) && $_SESSION['google_oauth_redirect_uri'] !== ''
        ? $_SESSION['google_oauth_redirect_uri']
        : google_oauth_redirect_uri();
    $invite = is_string($_SESSION['google_oauth_invite'] ?? null) ? trim($_SESSION['google_oauth_invite']) : '';
    $next = safe_next_page(is_string($_SESSION['google_oauth_next'] ?? null) ? $_SESSION['google_oauth_next'] : null);
    unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_redirect_uri']);

    try {
        $tokens = google_oauth_exchange_code($code, $redirectUri);
        $accessToken = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : '';
        $profile = google_oauth_fetch_profile($accessToken);
        $result = complete_google_sign_in($profile, $invite);
    } catch (InvalidArgumentException $exception) {
        google_oauth_fail($exception->getMessage());
    } catch (Throwable $exception) {
        error_log('Google OAuth failed: ' . $exception->getMessage());
        google_oauth_fail('Google sign-in did not complete. Try again.');
    }

    unset($_SESSION['google_oauth_invite'], $_SESSION['google_oauth_next']);
    establish_user_session((int) $result['id']);
    if (!empty($result['joined'])) {
        $joined = current_user();
        $householdLabel = is_array($joined) ? (string) ($joined['household_name'] ?? 'this household') : 'this household';
        flash('success', 'You have joined ' . $householdLabel . '. HomeLedger will only show this household\'s data.');
    }
    redirect($next);
}

/**
 * @return array<string, mixed>
 */
function google_oauth_exchange_code(string $code, string $redirectUri): array
{
    $raw = google_http_request('POST', GOOGLE_OAUTH_TOKEN_URL, [
        'code' => $code,
        'client_id' => google_oauth_client_id(),
        'client_secret' => google_oauth_client_secret(),
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);
    $data = google_decode_json($raw);
    if (!is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
        throw new RuntimeException('Google did not return an access token.');
    }

    return $data;
}

/**
 * @return array{sub: string, email: string, name: string}
 */
function google_oauth_fetch_profile(string $accessToken): array
{
    if ($accessToken === '') {
        throw new RuntimeException('Google did not return an access token.');
    }

    $raw = google_http_request('GET', GOOGLE_OAUTH_USERINFO_URL, [], [
        'Authorization: Bearer ' . $accessToken,
    ]);
    $data = google_decode_json($raw);
    $email = normalize_login_email(is_string($data['email'] ?? null) ? $data['email'] : '');
    $sub = trim(is_string($data['sub'] ?? null) ? $data['sub'] : '');
    $name = trim(is_string($data['name'] ?? null) ? $data['name'] : '');
    if ($name === '' && is_string($data['given_name'] ?? null)) {
        $name = trim($data['given_name']);
    }
    if ($email === '' || !valid_login_email($email)) {
        throw new InvalidArgumentException(
            'Google did not share an email address. Try another Google account or sign in with a password.'
        );
    }
    if ($sub === '' || text_length($sub) > 255) {
        throw new InvalidArgumentException('Google sign-in did not complete. Try again.');
    }

    return [
        'sub' => $sub,
        'email' => $email,
        'name' => $name,
    ];
}

/**
 * @return array<string, mixed>
 */
function google_decode_json(string $raw): array
{
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Google returned an unreadable response.');
    }
    if (!is_array($data)) {
        throw new RuntimeException('Google returned an unreadable response.');
    }

    return $data;
}

/**
 * @param array<string, string> $fields
 * @param list<string> $headers
 */
function google_http_request(string $method, string $url, array $fields = [], array $headers = []): string
{
    $body = $method === 'POST' ? http_build_query($fields) : '';
    $headerList = $headers;
    if ($method === 'POST') {
        array_unshift($headerList, 'Content-Type: application/x-www-form-urlencoded');
    }
    $headerList[] = 'Accept: application/json';

    if (function_exists('curl_init')) {
        return google_http_request_curl($method, $url, $body, $headerList);
    }

    return google_http_request_fopen($method, $url, $body, $headerList);
}

/**
 * @param list<string> $headers
 */
function google_http_request_curl(string $method, string $url, string $body, array $headers): string
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Google request failed.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => GOOGLE_OAUTH_TIMEOUT_SECONDS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($handle, $options);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($response)) {
        throw new RuntimeException('Google request failed.');
    }
    if ($status >= 400) {
        throw new RuntimeException('Google request failed.');
    }

    return $response;
}

/**
 * @param list<string> $headers
 */
function google_http_request_fopen(string $method, string $url, string $body, array $headers): string
{
    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'timeout' => GOOGLE_OAUTH_TIMEOUT_SECONDS,
        'ignore_errors' => true,
        'follow_location' => 0,
    ];
    if ($method === 'POST') {
        $http['content'] = $body;
    }

    $response = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    $status = google_http_status_from_headers($http_response_header ?? null);
    if (!is_string($response)) {
        throw new RuntimeException('Google request failed.');
    }
    if ($status >= 400) {
        throw new RuntimeException('Google request failed.');
    }

    return $response;
}

/** @param list<string>|null $headers */
function google_http_status_from_headers(?array $headers): int
{
    if ($headers === null || $headers === []) {
        return 0;
    }
    $status = 0;
    foreach ($headers as $line) {
        if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d+)#', $line, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    return $status;
}
