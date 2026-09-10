<?php

declare(strict_types=1);

const HOUSEHOLD_BACKUP_MAGIC = 'HLB1';
const HOUSEHOLD_BACKUP_VERSION = 1;
const HOUSEHOLD_BACKUP_KDF_ITERATIONS = 100000;
const HOUSEHOLD_BACKUP_MAX_BYTES = 2097152;

function household_backup_passphrase(string $password, string $confirm): string
{
    $password = trim($password);
    if (text_length($password) < AUTH_MIN_PASSWORD_LENGTH) {
        throw new InvalidArgumentException('Use a backup passphrase of at least 12 characters.');
    }
    if (!hash_equals($password, $confirm)) {
        throw new InvalidArgumentException('The backup passphrase confirmation does not match.');
    }

    return $password;
}

function assert_current_user_password(string $password, string $message = 'The current password is not correct.'): void
{
    $user = current_user();
    if ($user === null) {
        throw new RuntimeException('Sign in to continue.');
    }
    $account = household_member_row(db(), (int) $user['household_id'], (int) $user['id']);
    if ($account === null || !password_verify($password, (string) $account['password_hash'])) {
        throw new InvalidArgumentException($message);
    }
}

function household_backup_derive_key(string $passphrase, string $salt, int $iterations): string
{
    if ($iterations < 50000 || $iterations > 500000) {
        throw new InvalidArgumentException('This backup file is not valid.');
    }
    $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('The backup key could not be derived.');
    }

    return $key;
}

function encrypt_household_backup_payload(string $plaintext, string $passphrase): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required for encrypted backups.');
    }
    $salt = random_bytes(16);
    $iv = random_bytes(12);
    $key = household_backup_derive_key($passphrase, $salt, HOUSEHOLD_BACKUP_KDF_ITERATIONS);
    $tag = '';
    $aad = HOUSEHOLD_BACKUP_MAGIC . pack('C', HOUSEHOLD_BACKUP_VERSION);
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('The backup could not be encrypted.');
    }

    return HOUSEHOLD_BACKUP_MAGIC
        . pack('C', HOUSEHOLD_BACKUP_VERSION)
        . pack('N', HOUSEHOLD_BACKUP_KDF_ITERATIONS)
        . $salt
        . $iv
        . $tag
        . $ciphertext;
}

function decrypt_household_backup_payload(string $blob, string $passphrase): string
{
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required for encrypted backups.');
    }
    $header = 4 + 1 + 4 + 16 + 12 + 16;
    if (strlen($blob) < $header + 1) {
        throw new InvalidArgumentException('That file is not a HomeLedger backup.');
    }
    $magic = substr($blob, 0, 4);
    $version = ord($blob[4]);
    if ($magic !== HOUSEHOLD_BACKUP_MAGIC || $version !== HOUSEHOLD_BACKUP_VERSION) {
        throw new InvalidArgumentException('That file is not a HomeLedger backup.');
    }
    $unpacked = unpack('Niter', substr($blob, 5, 4));
    $iterations = is_array($unpacked) ? (int) $unpacked['iter'] : 0;
    $salt = substr($blob, 9, 16);
    $iv = substr($blob, 25, 12);
    $tag = substr($blob, 37, 16);
    $ciphertext = substr($blob, 53);
    $key = household_backup_derive_key($passphrase, $salt, $iterations);
    $aad = HOUSEHOLD_BACKUP_MAGIC . pack('C', $version);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
    if (!is_string($plaintext) || $plaintext === '') {
        throw new InvalidArgumentException('That backup passphrase is not correct, or the file is damaged.');
    }

    return $plaintext;
}

/**
 * @return array<string, mixed>
 */
function build_household_backup_document(int $householdId): array
{
    $pdo = db();
    $household = $pdo->prepare('SELECT name FROM households WHERE id = ?');
    $household->execute([$householdId]);
    $householdName = (string) $household->fetchColumn();

    $categories = $pdo->prepare(
        'SELECT c.id, c.name, c.type, c.colour, c.sort_order, b.amount AS budget_amount
         FROM categories c
         LEFT JOIN category_budgets b ON b.household_id = c.household_id AND b.category_id = c.id
         WHERE c.household_id = ?
         ORDER BY c.type DESC, c.sort_order, c.name'
    );
    $categories->execute([$householdId]);
    $categoryRows = $categories->fetchAll() ?: [];
    $categoryById = [];
    $exportedCategories = [];
    foreach ($categoryRows as $row) {
        $id = (int) $row['id'];
        $categoryById[$id] = $row;
        $exportedCategories[] = [
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'colour' => (string) $row['colour'],
            'sort_order' => (int) $row['sort_order'],
            'budget' => $row['budget_amount'] !== null ? (float) $row['budget_amount'] : null,
        ];
    }

    $recurring = $pdo->prepare(
        'SELECT id, type, description, amount, category_id, frequency, interval_count,
                start_date, next_due_date, end_date, notes, active
         FROM recurring_entries
         WHERE household_id = ?
         ORDER BY id'
    );
    $recurring->execute([$householdId]);
    $exportedRecurring = [];
    $recurringKeys = [];
    foreach ($recurring->fetchAll() ?: [] as $i => $row) {
        $key = 'r' . ((int) $i + 1);
        $recurringKeys[(int) $row['id']] = $key;
        $category = $categoryById[(int) $row['category_id']] ?? null;
        $exportedRecurring[] = [
            'key' => $key,
            'type' => (string) $row['type'],
            'description' => (string) $row['description'],
            'amount' => (float) $row['amount'],
            'category_name' => is_array($category) ? (string) $category['name'] : '',
            'category_type' => is_array($category) ? (string) $category['type'] : (string) $row['type'],
            'frequency' => (string) $row['frequency'],
            'interval' => (int) $row['interval_count'],
            'start_date' => (string) $row['start_date'],
            'next_due' => (string) $row['next_due_date'],
            'end_date' => $row['end_date'] !== null ? (string) $row['end_date'] : null,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : '',
            'active' => (int) $row['active'] === 1,
        ];
    }

    $transactions = $pdo->prepare(
        'SELECT type, description, amount, category_id, transaction_date, notes, source, recurring_entry_id
         FROM transactions
         WHERE household_id = ?
         ORDER BY transaction_date ASC, id ASC'
    );
    $transactions->execute([$householdId]);
    $exportedTransactions = [];
    foreach ($transactions->fetchAll() ?: [] as $row) {
        $category = $categoryById[(int) $row['category_id']] ?? null;
        $recurringId = $row['recurring_entry_id'] !== null ? (int) $row['recurring_entry_id'] : 0;
        $exportedTransactions[] = [
            'date' => (string) $row['transaction_date'],
            'type' => (string) $row['type'],
            'description' => (string) $row['description'],
            'amount' => (float) $row['amount'],
            'category_name' => is_array($category) ? (string) $category['name'] : '',
            'category_type' => is_array($category) ? (string) $category['type'] : (string) $row['type'],
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : '',
            'source' => (string) $row['source'],
            'recurring_key' => $recurringId > 0 ? ($recurringKeys[$recurringId] ?? null) : null,
        ];
    }

    return [
        'format' => 'homeledger-backup',
        'version' => 1,
        'exported_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'household' => ['name' => $householdName],
        'categories' => $exportedCategories,
        'recurring' => $exportedRecurring,
        'transactions' => $exportedTransactions,
    ];
}

function download_household_backup(string $passphrase, string $confirm): never
{
    $passphrase = household_backup_passphrase($passphrase, $confirm);
    $document = build_household_backup_document(current_household_id());
    $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('The backup could not be prepared.');
    }
    $blob = encrypt_household_backup_payload($json, $passphrase);
    $basename = 'homeledger-' . portability_download_slug() . '-backup-' . date('Y-m-d') . '.hlb';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $basename . '"');
    header('X-Content-Type-Options: nosniff');
    echo $blob;
    exit;
}

function household_backup_uploaded_blob(): string
{
    $file = $_FILES['backup_file'] ?? null;
    if (!is_array($file)) {
        throw new InvalidArgumentException('Choose a HomeLedger backup file.');
    }
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose a HomeLedger backup file.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('That backup could not be uploaded. Try a smaller file.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > HOUSEHOLD_BACKUP_MAX_BYTES) {
        throw new InvalidArgumentException('Backup files must be 2 MB or smaller.');
    }
    $tmp = is_string($file['tmp_name'] ?? null) ? (string) $file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('That backup could not be read.');
    }
    $contents = file_get_contents($tmp);
    if (!is_string($contents) || $contents === '') {
        throw new InvalidArgumentException('That backup could not be read.');
    }

    return $contents;
}

/**
 * @param array<string, mixed> $document
 */
function validate_household_backup_document(array $document): void
{
    if (($document['format'] ?? '') !== 'homeledger-backup' || (int) ($document['version'] ?? 0) !== 1) {
        throw new InvalidArgumentException('That file is not a HomeLedger backup.');
    }
    if (!is_array($document['categories'] ?? null)
        || !is_array($document['recurring'] ?? null)
        || !is_array($document['transactions'] ?? null)
    ) {
        throw new InvalidArgumentException('That backup is missing ledger data.');
    }
    $count = count($document['categories']) + count($document['recurring']) + count($document['transactions']);
    if ($count > 20000) {
        throw new InvalidArgumentException('That backup is too large to restore.');
    }
}

function ensure_backup_category(PDO $pdo, int $householdId, string $name, string $type, string $colour, int $sortOrder): int
{
    $existing = match_household_category($householdId, $name, $type);
    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $name = normalize_category_name($name);
    $type = normalize_category_type($type);
    $colour = normalize_category_colour($colour, $type);
    $stmt = $pdo->prepare(
        'INSERT INTO categories (household_id, name, type, colour, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    try {
        $stmt->execute([$householdId, $name, $type, $colour, $sortOrder > 0 ? $sortOrder : 100]);
    } catch (PDOException $exception) {
        if (is_category_name_type_collision($exception)) {
            $again = match_household_category($householdId, $name, $type);
            if ($again !== null) {
                return (int) $again['id'];
            }
        }
        throw $exception;
    }

    return (int) $pdo->lastInsertId();
}

/**
 * @param array<string, mixed> $document
 * @return array{categories:int,recurring:int,transactions:int,skipped:int}
 */
function restore_household_backup_document(array $document, bool $replace): array
{
    assert_household_owner('Only the household owner can restore a backup.');
    validate_household_backup_document($document);
    $householdId = current_household_id();
    $pdo = db();
    $createdCategories = 0;
    $insertedRecurring = 0;
    $insertedTransactions = 0;
    $skipped = 0;

    $pdo->beginTransaction();
    try {
        if ($replace) {
            $pdo->prepare('DELETE FROM transactions WHERE household_id = ?')->execute([$householdId]);
            $pdo->prepare('DELETE FROM recurring_entries WHERE household_id = ?')->execute([$householdId]);
            $pdo->prepare('DELETE FROM category_budgets WHERE household_id = ?')->execute([$householdId]);
        }

        $categoryIds = [];
        foreach ($document['categories'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $type = (string) ($row['type'] ?? '');
            $colour = (string) ($row['colour'] ?? '');
            $sortOrder = (int) ($row['sort_order'] ?? 100);
            $id = ensure_backup_category($pdo, $householdId, $name, $type, $colour, $sortOrder);
            $createdCategories++;
            $categoryIds[$type . "\0" . strtolower($name)] = $id;
            $budget = $row['budget'] ?? null;
            if ($type === 'expense' && $budget !== null && $budget !== '') {
                $amount = parse_csv_amount((string) $budget);
                if ($amount !== false) {
                    $pdo->prepare(
                        'INSERT INTO category_budgets (household_id, category_id, amount)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE amount = VALUES(amount)'
                    )->execute([$householdId, $id, $amount]);
                }
            }
        }

        $recurringIds = [];
        $recurringStmt = $pdo->prepare(
            'INSERT INTO recurring_entries
                (household_id, type, description, amount, category_id, frequency, interval_count,
                 start_date, next_due_date, end_date, notes, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($document['recurring'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            $name = trim((string) ($row['category_name'] ?? ''));
            $categoryId = $categoryIds[$type . "\0" . strtolower($name)] ?? null;
            if ($categoryId === null) {
                $matched = match_household_category($householdId, $name, $type);
                $categoryId = $matched !== null ? (int) $matched['id'] : 0;
            }
            $description = trim((string) ($row['description'] ?? ''));
            $amount = parse_csv_amount((string) ($row['amount'] ?? ''));
            $frequency = (string) ($row['frequency'] ?? '');
            $interval = (int) ($row['interval'] ?? 0);
            $startDate = parse_csv_date((string) ($row['start_date'] ?? ''));
            $nextDue = parse_csv_date((string) ($row['next_due'] ?? '')) ?? $startDate;
            $endRaw = $row['end_date'] ?? null;
            $endDate = is_string($endRaw) && $endRaw !== '' ? parse_csv_date($endRaw) : null;
            $notes = trim((string) ($row['notes'] ?? ''));
            if ($categoryId < 1 || $description === '' || $amount === false || $startDate === null
                || !in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)
                || $interval < 1 || $interval > 365
            ) {
                throw new InvalidArgumentException('This backup has an invalid recurring entry.');
            }
            if (!$replace && household_recurring_exists(
                $householdId, $type, $description, $amount, $categoryId, $frequency, $interval, $startDate
            )) {
                $skipped++;
                continue;
            }
            $recurringStmt->execute([
                $householdId, $type, $description, $amount, $categoryId, $frequency, $interval,
                $startDate, $nextDue ?? $startDate, $endDate, $notes !== '' ? $notes : null,
                !empty($row['active']) ? 1 : 0,
            ]);
            $key = (string) ($row['key'] ?? '');
            if ($key !== '') {
                $recurringIds[$key] = (int) $pdo->lastInsertId();
            }
            $insertedRecurring++;
        }

        $txStmt = $pdo->prepare(
            'INSERT INTO transactions
                (household_id, type, description, amount, category_id, transaction_date, notes, source, recurring_entry_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($document['transactions'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            $name = trim((string) ($row['category_name'] ?? ''));
            $categoryId = $categoryIds[$type . "\0" . strtolower($name)] ?? null;
            if ($categoryId === null) {
                $matched = match_household_category($householdId, $name, $type);
                $categoryId = $matched !== null ? (int) $matched['id'] : 0;
            }
            $description = trim((string) ($row['description'] ?? ''));
            $amount = parse_csv_amount((string) ($row['amount'] ?? ''));
            $date = parse_csv_date((string) ($row['date'] ?? ''));
            $notes = trim((string) ($row['notes'] ?? ''));
            $source = (string) ($row['source'] ?? 'manual');
            if (!in_array($source, ['manual', 'recurring'], true)) {
                $source = 'manual';
            }
            if ($categoryId < 1 || $description === '' || $amount === false || $date === null
                || !in_array($type, ['income', 'expense'], true)
            ) {
                throw new InvalidArgumentException('This backup has an invalid transaction.');
            }
            if (!$replace && household_transaction_exists(
                $householdId, $date, $type, $description, $amount, $categoryId, $notes
            )) {
                $skipped++;
                continue;
            }
            $recurringKey = is_string($row['recurring_key'] ?? null) ? (string) $row['recurring_key'] : '';
            $recurringId = $recurringKey !== '' ? ($recurringIds[$recurringKey] ?? null) : null;
            if ($source !== 'recurring') {
                $recurringId = null;
                $source = 'manual';
            }
            try {
                $txStmt->execute([
                    $householdId, $type, $description, $amount, $categoryId, $date,
                    $notes !== '' ? $notes : null, $source, $recurringId,
                ]);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    $txStmt->execute([
                        $householdId, $type, $description, $amount, $categoryId, $date,
                        $notes !== '' ? $notes : null, 'manual', null,
                    ]);
                } else {
                    throw $exception;
                }
            }
            $insertedTransactions++;
        }

        bump_household_state($pdo, $householdId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    log_household_activity(
        $householdId,
        current_actor_user_id(),
        'backup_restored',
        ($replace ? 'Replaced ledger from backup' : 'Restored backup')
        . ': ' . $insertedTransactions . ' transactions, ' . $insertedRecurring . ' recurring'
    );

    return [
        'categories' => $createdCategories,
        'recurring' => $insertedRecurring,
        'transactions' => $insertedTransactions,
        'skipped' => $skipped,
    ];
}

function restore_household_backup_from_upload(
    string $passphrase,
    string $currentPassword,
    string $typedHouseholdId,
    bool $replace
): array {
    $user = current_user();
    if ($user === null) {
        throw new RuntimeException('Sign in to restore a backup.');
    }
    assert_current_user_password($currentPassword);
    assert_household_id_confirmation(
        $typedHouseholdId,
        (string) ($user['household_public_code'] ?? ''),
        'Type the household ID exactly to confirm restore.'
    );
    if (text_length($passphrase) < 1) {
        throw new InvalidArgumentException('Enter the backup passphrase.');
    }
    $json = decrypt_household_backup_payload(household_backup_uploaded_blob(), $passphrase);
    $document = json_decode($json, true);
    if (!is_array($document)) {
        throw new InvalidArgumentException('That backup could not be read.');
    }

    return restore_household_backup_document($document, $replace);
}
