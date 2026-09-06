<?php

declare(strict_types=1);

const AUTH_MIN_PASSWORD_LENGTH = 12;
const AUTH_MAX_FAILURES = 5;
const AUTH_LOCK_MINUTES = 15;
const AUTH_IP_ATTEMPT_LIMIT = 20;
const AUTH_GENERIC_LOGIN_ERROR = 'Those details did not match.';
const AUTH_LOCK_MESSAGE = 'Too many sign-in attempts. Try again in 15 minutes.';

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
}

function normalize_login_email(string $email): string
{
    return strtolower(trim($email));
}

function valid_login_email(string $email): bool
{
    return $email !== ''
        && text_length($email) <= 190
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function users_exist(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

/** @return array<string, mixed>|null */
function current_user(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    if (!is_numeric($id)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.household_id, u.login, u.display_name,
                h.name AS household_name, h.public_code AS household_public_code,
                h.state_version AS household_state_version
         FROM users u
         JOIN households h ON h.id = u.household_id
         WHERE u.id = ?'
    );
    $stmt->execute([(int) $id]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

/** @return list<array<string, mixed>> */
function household_members_for_current(): array
{
    $stmt = db()->prepare(
        'SELECT id, login, display_name, created_at
         FROM users
         WHERE household_id = ?
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([current_household_id()]);

    return $stmt->fetchAll() ?: [];
}

function household_owner_user_id(?int $householdId = null): int
{
    $householdId = $householdId ?? current_household_id();
    $stmt = db()->prepare('SELECT owner_user_id FROM households WHERE id = ?');
    $stmt->execute([$householdId]);
    $ownerId = (int) $stmt->fetchColumn();

    if ($ownerId > 0) {
        $member = db()->prepare(
            'SELECT id FROM users WHERE id = ? AND household_id = ?'
        );
        $member->execute([$ownerId, $householdId]);
        if ((int) $member->fetchColumn() === $ownerId) {
            return $ownerId;
        }
    }

    $fallback = db()->prepare(
        'SELECT id FROM users WHERE household_id = ? ORDER BY created_at ASC, id ASC LIMIT 1'
    );
    $fallback->execute([$householdId]);
    $repaired = (int) $fallback->fetchColumn();
    if ($repaired > 0 && $repaired !== $ownerId) {
        $repair = db()->prepare('UPDATE households SET owner_user_id = ? WHERE id = ?');
        $repair->execute([$repaired, $householdId]);
    }

    return $repaired;
}

function current_user_is_household_owner(): bool
{
    $user = current_user();
    if ($user === null) {
        return false;
    }

    return (int) $user['id'] === household_owner_user_id((int) $user['household_id']);
}

function assert_household_owner(string $message = 'Only the household owner can manage invites.'): void
{
    if (!current_user_is_household_owner()) {
        throw new InvalidArgumentException($message);
    }
}

function is_authenticated(): bool
{
    return current_user() !== null;
}

function establish_user_session(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['old_form']);
}

function destroy_user_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function safe_next_page(?string $page): string
{
    if ($page === 'balance-sheet') {
        $page = 'statement';
    }
    if ($page === 'invite') {
        $page = 'household';
    }
    $allowed = ['dashboard', 'transactions', 'recurring', 'categories', 'statement', 'household'];

    return is_string($page) && in_array($page, $allowed, true) ? $page : 'dashboard';
}

function remember_form(array $fields): void
{
    $_SESSION['old_form'] = $fields;
}

function old_form(string $key, string $fallback = ''): string
{
    $value = $_SESSION['old_form'][$key] ?? $fallback;

    return e(is_string($value) ? $value : $fallback);
}

function dummy_password_hash(): string
{
    static $hash = null;
    $hash ??= password_hash('homeledger-dummy-password-not-used', PASSWORD_DEFAULT);

    return $hash;
}

function prune_login_attempts(): void
{
    db()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 24 HOUR)');
}

function record_login_attempt(string $loginKey): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (ip, login_key) VALUES (?, ?)');
    $stmt->execute([client_ip(), $loginKey === '' ? '-' : $loginKey]);
}

function ip_login_blocked(): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip = ? AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute([client_ip()]);

    return (int) $stmt->fetchColumn() >= AUTH_IP_ATTEMPT_LIMIT;
}

/** @param array<string, mixed> $user */
function clear_expired_lock(array &$user): void
{
    if (empty($user['locked_until']) || strtotime((string) $user['locked_until']) > time()) {
        return;
    }

    $stmt = db()->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $user['failed_attempts'] = 0;
    $user['locked_until'] = null;
}

/** @param array<string, mixed> $user */
function register_failed_login(array $user): void
{
    $attempts = (int) $user['failed_attempts'] + 1;
    $lockedUntil = null;
    if ($attempts >= AUTH_MAX_FAILURES) {
        $attempts = AUTH_MAX_FAILURES;
        $lockedUntil = (new DateTimeImmutable('+' . AUTH_LOCK_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
    }

    $stmt = db()->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?');
    $stmt->execute([$attempts, $lockedUntil, (int) $user['id']]);
}

function authenticate_with_password(string $email, string $password): void
{
    prune_login_attempts();
    $login = normalize_login_email($email);

    if (ip_login_blocked()) {
        throw new InvalidArgumentException(AUTH_LOCK_MESSAGE);
    }

    $stmt = db()->prepare(
        'SELECT id, login, password_hash, failed_attempts, locked_until, email_verified_at FROM users WHERE login = ?'
    );
    $stmt->execute([$login]);
    $user = $stmt->fetch();

    if (!is_array($user)) {
        password_verify($password, dummy_password_hash());
        record_login_attempt($login);
        throw new InvalidArgumentException(AUTH_GENERIC_LOGIN_ERROR);
    }

    clear_expired_lock($user);

    if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
        record_login_attempt($login);
        throw new InvalidArgumentException(AUTH_LOCK_MESSAGE);
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        record_login_attempt($login);
        register_failed_login($user);
        throw new InvalidArgumentException(AUTH_GENERIC_LOGIN_ERROR);
    }

    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
    }

    $reset = db()->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?');
    $reset->execute([(int) $user['id']]);

    if (empty($user['email_verified_at'])) {
        $_SESSION['pending_email_confirm'] = $login;
        throw new InvalidArgumentException(
            'Confirm this email to activate HomeLedger. Check your inbox or resend the confirmation email.'
        );
    }

    unset($_SESSION['pending_email_confirm']);
    establish_user_session((int) $user['id']);
}

function household_name_from_display(string $displayName, string $householdName): string
{
    $householdName = trim($householdName);
    if ($householdName === '') {
        $householdName = $displayName . "'s household";
    }
    if (text_length($householdName) > 80) {
        throw new InvalidArgumentException('Enter a household name of up to 80 characters.');
    }

    return $householdName;
}

function household_has_money_rows(PDO $pdo, int $householdId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE household_id = ?');
    $stmt->execute([$householdId]);

    return (int) $stmt->fetchColumn() > 0;
}

function adopt_or_create_household(PDO $pdo, string $name): int
{
    $existing = $pdo->query('SELECT id FROM households ORDER BY id ASC LIMIT 1 FOR UPDATE')->fetchColumn();
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0 && $existing && household_has_money_rows($pdo, (int) $existing)) {
        $householdId = (int) $existing;
        $rename = $pdo->prepare('UPDATE households SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $rename->execute([$name, $householdId]);
        assign_household_public_code($pdo, $householdId);

        return $householdId;
    }

    for ($attempt = 0; $attempt < HOUSEHOLD_PUBLIC_CODE_ATTEMPTS; $attempt++) {
        try {
            $stmt = $pdo->prepare('INSERT INTO households (name, public_code) VALUES (?, ?)');
            $stmt->execute([$name, allocate_household_public_code($pdo)]);
            $householdId = (int) $pdo->lastInsertId();
            seed_household_categories($pdo, $householdId);

            return $householdId;
        } catch (PDOException $exception) {
            if (!is_household_public_code_collision($exception) || $attempt === HOUSEHOLD_PUBLIC_CODE_ATTEMPTS - 1) {
                throw $exception;
            }
        }
    }

    throw new RuntimeException('Could not allocate a household ID.');
}

/**
 * @return array{id: int, login: string, confirm_token: ?string, confirm_expires: ?DateTimeImmutable}
 */
function create_household_owner(
    string $displayName,
    string $email,
    string $password,
    string $confirm,
    string $householdName = '',
    string $rawInvite = ''
): array {
    prune_login_attempts();
    if (ip_login_blocked()) {
        throw new InvalidArgumentException(AUTH_LOCK_MESSAGE);
    }

    $displayName = trim($displayName);
    $login = normalize_login_email($email);
    $rawInvite = trim($rawInvite);

    if ($displayName === '' || text_length($displayName) > 80) {
        throw new InvalidArgumentException('Enter a display name of up to 80 characters.');
    }
    if (!valid_login_email($login)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (text_length($password) < AUTH_MIN_PASSWORD_LENGTH) {
        throw new InvalidArgumentException('Use a password of at least 12 characters.');
    }
    if (!hash_equals($password, $confirm)) {
        throw new InvalidArgumentException('The password confirmation does not match.');
    }

    $pdo = db();
    $exists = $pdo->prepare('SELECT id FROM users WHERE login = ?');
    $exists->execute([$login]);
    if ($exists->fetch()) {
        record_login_attempt($login);
        throw new InvalidArgumentException('An account with that email already exists. Sign in instead.');
    }

    $confirmToken = null;
    $confirmExpires = null;
    $pdo->beginTransaction();
    try {
        if ($rawInvite !== '') {
            $householdId = accept_open_invite($pdo, $rawInvite, $login);
            $verifiedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $tokenHash = null;
            $tokenExpires = null;
        } else {
            $householdName = household_name_from_display($displayName, $householdName);
            $householdId = adopt_or_create_household($pdo, $householdName);
            $verifiedAt = null;
            $confirmToken = bin2hex(random_bytes(32));
            $confirmExpires = new DateTimeImmutable('+' . EMAIL_CONFIRM_TTL_HOURS . ' hours');
            $tokenHash = hash_email_confirm_token($confirmToken);
            $tokenExpires = $confirmExpires->format('Y-m-d H:i:s');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO users
                (household_id, login, display_name, password_hash, email_verified_at,
                 email_confirm_token_hash, email_confirm_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $householdId,
            $login,
            $displayName,
            password_hash($password, PASSWORD_DEFAULT),
            $verifiedAt,
            $tokenHash,
            $tokenExpires,
        ]);
        $userId = (int) $pdo->lastInsertId();
        if ($rawInvite === '') {
            $setOwner = $pdo->prepare('UPDATE households SET owner_user_id = ? WHERE id = ?');
            $setOwner->execute([$userId, $householdId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'id' => $userId,
        'login' => $login,
        'confirm_token' => $confirmToken,
        'confirm_expires' => $confirmExpires,
    ];
}

function accept_open_invite(PDO $pdo, string $rawToken, string $login): int
{
    $hash = hash_invite_token($rawToken);
    $stmt = $pdo->prepare(
        'SELECT id, household_id, email, token_hash, expires_at, accepted_at
         FROM household_invites
         WHERE token_hash = ?
         FOR UPDATE'
    );
    $stmt->execute([$hash]);
    $invite = $stmt->fetch();
    if (
        !is_array($invite)
        || !hash_equals((string) $invite['token_hash'], $hash)
        || !invite_is_open($invite)
    ) {
        throw new InvalidArgumentException(
            'This invite is invalid, expired or already used. Create your own household or ask for a new invite.'
        );
    }
    if (!hash_equals(normalize_login_email((string) $invite['email']), $login)) {
        throw new InvalidArgumentException('Use the email address this invite was sent to.');
    }

    $mark = $pdo->prepare(
        'UPDATE household_invites
         SET accepted_at = NOW()
         WHERE id = ? AND accepted_at IS NULL AND expires_at > NOW()'
    );
    $mark->execute([(int) $invite['id']]);
    if ($mark->rowCount() !== 1) {
        throw new InvalidArgumentException(
            'This invite is invalid, expired or already used. Create your own household or ask for a new invite.'
        );
    }

    $householdId = (int) $invite['household_id'];
    bump_household_state($pdo, $householdId);

    return $householdId;
}

function update_current_display_name(string $displayName): void
{
    $user = current_user();
    if ($user === null) {
        throw new RuntimeException('Sign in to update your profile.');
    }

    $displayName = trim($displayName);
    if ($displayName === '' || text_length($displayName) > 80) {
        throw new InvalidArgumentException('Enter a display name of up to 80 characters.');
    }

    $stmt = db()->prepare('UPDATE users SET display_name = ? WHERE id = ? AND household_id = ?');
    $stmt->execute([$displayName, (int) $user['id'], current_household_id()]);
}

function update_current_household_name(string $householdName): void
{
    $householdName = trim($householdName);
    if ($householdName === '' || text_length($householdName) > 80) {
        throw new InvalidArgumentException('Enter a household name of up to 80 characters.');
    }

    $stmt = db()->prepare(
        'UPDATE households SET name = ?, state_version = state_version + 1 WHERE id = ?'
    );
    $stmt->execute([$householdName, current_household_id()]);
}

function change_current_user_password(string $current, string $new, string $confirm): void
{
    $user = current_user();
    if ($user === null) {
        throw new RuntimeException('Sign in to change your password.');
    }
    if (text_length($new) < AUTH_MIN_PASSWORD_LENGTH) {
        throw new InvalidArgumentException('Use a password of at least 12 characters.');
    }
    if (!hash_equals($new, $confirm)) {
        throw new InvalidArgumentException('The password confirmation does not match.');
    }
    if (hash_equals($current, $new)) {
        throw new InvalidArgumentException('Choose a new password that is different from the current one.');
    }

    $stmt = db()->prepare(
        'SELECT id, password_hash FROM users WHERE id = ? AND household_id = ?'
    );
    $stmt->execute([(int) $user['id'], current_household_id()]);
    $row = $stmt->fetch();
    if (!is_array($row) || !password_verify($current, (string) $row['password_hash'])) {
        throw new InvalidArgumentException('The current password is not correct.');
    }

    $update = db()->prepare(
        'UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL
         WHERE id = ? AND household_id = ?'
    );
    $update->execute([
        password_hash($new, PASSWORD_DEFAULT),
        (int) $user['id'],
        current_household_id(),
    ]);
    establish_user_session((int) $user['id']);
}
