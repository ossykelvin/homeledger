<?php

declare(strict_types=1);

const CSV_IMPORT_MAX_BYTES = 1048576;
const CSV_IMPORT_MAX_ROWS = 2000;
const CSV_IMPORT_PREVIEW_TTL = 900;

function csv_formula_safe(string $value): string
{
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}

function csv_encode_row(array $fields): string
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        throw new RuntimeException('CSV could not be written.');
    }
    fputcsv($handle, $fields);
    rewind($handle);
    $line = stream_get_contents($handle);
    fclose($handle);
    if ($line === false) {
        throw new RuntimeException('CSV could not be written.');
    }

    return $line;
}

/** @param list<list<string>> $rows */
function csv_document(array $headers, array $rows): string
{
    $out = csv_encode_row($headers);
    foreach ($rows as $row) {
        $safe = [];
        foreach ($row as $cell) {
            $safe[] = csv_formula_safe((string) $cell);
        }
        $out .= csv_encode_row($safe);
    }

    return "\xEF\xBB\xBF" . $out;
}

function portability_download_slug(): string
{
    $user = current_user();
    $name = is_array($user) ? (string) ($user['household_name'] ?? 'household') : 'household';
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'household');
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'household';
}

function send_csv_download(string $basename, string $csv): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $basename . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo $csv;
    exit;
}

/** @return array<string, mixed>|null */
function match_household_category(int $householdId, string $name, string $type): ?array
{
    $name = trim($name);
    if ($name === '' || !in_array($type, ['income', 'expense'], true)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, name, type, colour, sort_order
         FROM categories
         WHERE household_id = ? AND type = ? AND name = ?
         LIMIT 1'
    );
    $stmt->execute([$householdId, $type, $name]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function parse_csv_amount(string $raw): float|false
{
    $raw = trim(str_replace(["\xC2\xA3", '£', '$', '€', ',', ' '], '', $raw));
    if ($raw === '' || !is_numeric($raw)) {
        return false;
    }
    $amount = round((float) $raw, 2);
    if ($amount <= 0 || $amount > 99999999999.99) {
        return false;
    }

    return $amount;
}

function parse_csv_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'j/n/Y'] as $format) {
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        if (!$parsed instanceof DateTimeImmutable) {
            continue;
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            continue;
        }

        return $parsed->format('Y-m-d');
    }

    return null;
}

function csv_normalize_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
    $header = str_replace(['_', '-'], ' ', $header);
    $header = preg_replace('/\s+/', ' ', $header) ?? $header;

    return $header;
}

/**
 * @param list<string> $headers
 * @return array<string, int>
 */
function csv_header_index(array $headers): array
{
    $aliases = [
        'date' => 'date',
        'transaction date' => 'date',
        'transactiondate' => 'date',
        'type' => 'type',
        'description' => 'description',
        'desc' => 'description',
        'amount' => 'amount',
        'category' => 'category',
        'category name' => 'category',
        'notes' => 'notes',
        'note' => 'notes',
        'source' => 'source',
        'frequency' => 'frequency',
        'interval' => 'interval',
        'interval count' => 'interval',
        'start date' => 'start_date',
        'startdate' => 'start_date',
        'next due' => 'next_due',
        'next due date' => 'next_due',
        'end date' => 'end_date',
        'active' => 'active',
    ];
    $index = [];
    foreach ($headers as $i => $header) {
        $key = csv_normalize_header((string) $header);
        $mapped = $aliases[$key] ?? $key;
        if (!isset($index[$mapped])) {
            $index[$mapped] = (int) $i;
        }
    }

    return $index;
}

/**
 * @param array<string, int> $index
 * @param list<string|null> $row
 */
function csv_cell(array $index, array $row, string $key): string
{
    if (!isset($index[$key])) {
        return '';
    }
    $value = $row[$index[$key]] ?? '';

    return is_string($value) ? trim($value) : trim((string) $value);
}

function csv_detect_kind(array $index): string
{
    if (isset($index['frequency']) && isset($index['start_date'])) {
        return 'recurring';
    }
    if (isset($index['date'], $index['type'], $index['description'], $index['amount'], $index['category'])) {
        return 'transactions';
    }

    throw new InvalidArgumentException(
        'This CSV needs a header row. Use Date, Type, Description, Amount, Category and Notes, or the recurring columns from the export.'
    );
}

function household_transactions_csv(): string
{
    $householdId = current_household_id();
    $stmt = db()->prepare(
        'SELECT t.transaction_date, t.type, t.description, t.amount, c.name AS category_name,
                t.notes, t.source
         FROM transactions t
         JOIN categories c ON c.id = t.category_id AND c.household_id = t.household_id
         WHERE t.household_id = ?
         ORDER BY t.transaction_date ASC, t.id ASC'
    );
    $stmt->execute([$householdId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            (string) $row['transaction_date'],
            (string) $row['type'],
            (string) $row['description'],
            number_format((float) $row['amount'], 2, '.', ''),
            (string) $row['category_name'],
            (string) ($row['notes'] ?? ''),
            (string) $row['source'],
        ];
    }

    return csv_document(['Date', 'Type', 'Description', 'Amount', 'Category', 'Notes', 'Source'], $rows);
}

function household_recurring_csv(): string
{
    $householdId = current_household_id();
    $stmt = db()->prepare(
        'SELECT r.type, r.description, r.amount, c.name AS category_name, r.frequency, r.interval_count,
                r.start_date, r.next_due_date, r.end_date, r.notes, r.active
         FROM recurring_entries r
         JOIN categories c ON c.id = r.category_id AND c.household_id = r.household_id
         WHERE r.household_id = ?
         ORDER BY r.start_date ASC, r.id ASC'
    );
    $stmt->execute([$householdId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            (string) $row['type'],
            (string) $row['description'],
            number_format((float) $row['amount'], 2, '.', ''),
            (string) $row['category_name'],
            (string) $row['frequency'],
            (string) (int) $row['interval_count'],
            (string) $row['start_date'],
            (string) $row['next_due_date'],
            (string) ($row['end_date'] ?? ''),
            (string) ($row['notes'] ?? ''),
            ((int) $row['active'] === 1) ? 'yes' : 'no',
        ];
    }

    return csv_document(
        ['Type', 'Description', 'Amount', 'Category', 'Frequency', 'Interval', 'Start date', 'Next due', 'End date', 'Notes', 'Active'],
        $rows
    );
}

function export_household_transactions_csv(): never
{
    send_csv_download(
        'homeledger-' . portability_download_slug() . '-transactions-' . date('Y-m-d'),
        household_transactions_csv()
    );
}

function export_household_recurring_csv(): never
{
    send_csv_download(
        'homeledger-' . portability_download_slug() . '-recurring-' . date('Y-m-d'),
        household_recurring_csv()
    );
}

/** @return list<list<string|null>> */
function csv_read_rows(string $contents): array
{
    $contents = str_replace("\r\n", "\n", $contents);
    $contents = str_replace("\r", "\n", $contents);
    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $contents = substr($contents, 3);
    }
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        throw new RuntimeException('CSV could not be read.');
    }
    fwrite($handle, $contents);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

function csv_uploaded_contents(): string
{
    $file = $_FILES['csv_file'] ?? null;
    if (!is_array($file)) {
        throw new InvalidArgumentException('Choose a CSV file to check.');
    }
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose a CSV file to check.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('That CSV could not be uploaded. Try a smaller file.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1) {
        throw new InvalidArgumentException('That CSV is empty.');
    }
    if ($size > CSV_IMPORT_MAX_BYTES) {
        throw new InvalidArgumentException('CSV files must be 1 MB or smaller.');
    }
    $tmp = is_string($file['tmp_name'] ?? null) ? (string) $file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('That CSV could not be read.');
    }
    $contents = file_get_contents($tmp);
    if (!is_string($contents) || $contents === '') {
        throw new InvalidArgumentException('That CSV could not be read.');
    }

    return $contents;
}

/**
 * @param list<string|null> $row
 * @param array<string, int> $index
 * @return array<string, mixed>
 */
function csv_parse_transaction_row(array $row, array $index, int $householdId, int $line): array
{
    $type = strtolower(csv_cell($index, $row, 'type'));
    $description = csv_cell($index, $row, 'description');
    $amount = parse_csv_amount(csv_cell($index, $row, 'amount'));
    $categoryName = csv_cell($index, $row, 'category');
    $date = parse_csv_date(csv_cell($index, $row, 'date'));
    $notes = csv_cell($index, $row, 'notes');

    if ($date === null) {
        throw new InvalidArgumentException('Row ' . $line . ': enter a valid date (YYYY-MM-DD or DD/MM/YYYY).');
    }
    if (!in_array($type, ['income', 'expense'], true)) {
        throw new InvalidArgumentException('Row ' . $line . ': type must be income or expense.');
    }
    if ($description === '' || text_length($description) > 160) {
        throw new InvalidArgumentException('Row ' . $line . ': enter a description of up to 160 characters.');
    }
    if ($amount === false) {
        throw new InvalidArgumentException('Row ' . $line . ': enter an amount greater than zero.');
    }
    if ($categoryName === '') {
        throw new InvalidArgumentException('Row ' . $line . ': enter a category name.');
    }
    $category = match_household_category($householdId, $categoryName, $type);
    if ($category === null) {
        throw new InvalidArgumentException(
            'Row ' . $line . ': category ' . $categoryName . ' was not found for ' . $type . '.'
        );
    }
    if (text_length($notes) > 500) {
        throw new InvalidArgumentException('Row ' . $line . ': notes cannot be longer than 500 characters.');
    }

    return [
        'date' => $date,
        'type' => $type,
        'description' => $description,
        'amount' => $amount,
        'category_id' => (int) $category['id'],
        'category_name' => (string) $category['name'],
        'notes' => $notes,
    ];
}

/**
 * @param list<string|null> $row
 * @param array<string, int> $index
 * @return array<string, mixed>
 */
function csv_parse_recurring_row(array $row, array $index, int $householdId, int $line): array
{
    $type = strtolower(csv_cell($index, $row, 'type'));
    $description = csv_cell($index, $row, 'description');
    $amount = parse_csv_amount(csv_cell($index, $row, 'amount'));
    $categoryName = csv_cell($index, $row, 'category');
    $frequency = strtolower(csv_cell($index, $row, 'frequency'));
    $intervalRaw = csv_cell($index, $row, 'interval');
    $startDate = parse_csv_date(csv_cell($index, $row, 'start_date'));
    $nextDueRaw = csv_cell($index, $row, 'next_due');
    $endRaw = csv_cell($index, $row, 'end_date');
    $notes = csv_cell($index, $row, 'notes');
    $activeRaw = strtolower(csv_cell($index, $row, 'active'));

    if (!in_array($type, ['income', 'expense'], true)) {
        throw new InvalidArgumentException('Row ' . $line . ': type must be income or expense.');
    }
    if ($description === '' || text_length($description) > 160) {
        throw new InvalidArgumentException('Row ' . $line . ': enter a description of up to 160 characters.');
    }
    if ($amount === false) {
        throw new InvalidArgumentException('Row ' . $line . ': enter an amount greater than zero.');
    }
    if ($categoryName === '') {
        throw new InvalidArgumentException('Row ' . $line . ': enter a category name.');
    }
    $category = match_household_category($householdId, $categoryName, $type);
    if ($category === null) {
        throw new InvalidArgumentException(
            'Row ' . $line . ': category ' . $categoryName . ' was not found for ' . $type . '.'
        );
    }
    if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        throw new InvalidArgumentException('Row ' . $line . ': frequency must be daily, weekly, monthly or yearly.');
    }
    $interval = ctype_digit($intervalRaw) ? (int) $intervalRaw : 0;
    if ($interval < 1 || $interval > 365) {
        throw new InvalidArgumentException('Row ' . $line . ': interval must be between 1 and 365.');
    }
    if ($startDate === null) {
        throw new InvalidArgumentException('Row ' . $line . ': enter a valid start date.');
    }
    $nextDue = $nextDueRaw === '' ? $startDate : parse_csv_date($nextDueRaw);
    if ($nextDue === null) {
        throw new InvalidArgumentException('Row ' . $line . ': enter a valid next due date.');
    }
    $endDate = null;
    if ($endRaw !== '') {
        $endDate = parse_csv_date($endRaw);
        if ($endDate === null || $endDate < $startDate) {
            throw new InvalidArgumentException('Row ' . $line . ': end date must be on or after the start date.');
        }
    }
    if (text_length($notes) > 500) {
        throw new InvalidArgumentException('Row ' . $line . ': notes cannot be longer than 500 characters.');
    }
    $active = !in_array($activeRaw, ['no', '0', 'false', 'paused', 'inactive'], true);

    return [
        'type' => $type,
        'description' => $description,
        'amount' => $amount,
        'category_id' => (int) $category['id'],
        'category_name' => (string) $category['name'],
        'frequency' => $frequency,
        'interval' => $interval,
        'start_date' => $startDate,
        'next_due' => $nextDue,
        'end_date' => $endDate,
        'notes' => $notes,
        'active' => $active,
    ];
}

/**
 * @return array{kind:string,rows:list<array<string,mixed>>,errors:list<string>}
 */
function csv_preview_from_contents(string $contents, int $householdId): array
{
    $table = csv_read_rows($contents);
    if ($table === []) {
        throw new InvalidArgumentException('That CSV has no rows.');
    }
    $headerRow = array_shift($table);
    $index = csv_header_index(array_map(static fn ($cell): string => (string) $cell, $headerRow));
    $kind = csv_detect_kind($index);
    $errors = [];
    $rows = [];
    $line = 1;
    foreach ($table as $raw) {
        $line++;
        $empty = true;
        foreach ($raw as $cell) {
            if (trim((string) $cell) !== '') {
                $empty = false;
                break;
            }
        }
        if ($empty) {
            continue;
        }
        if (count($rows) + count($errors) >= CSV_IMPORT_MAX_ROWS) {
            $errors[] = 'This file has more than ' . CSV_IMPORT_MAX_ROWS . ' data rows. Split it and try again.';
            break;
        }
        try {
            $rows[] = $kind === 'recurring'
                ? csv_parse_recurring_row($raw, $index, $householdId, $line)
                : csv_parse_transaction_row($raw, $index, $householdId, $line);
        } catch (InvalidArgumentException $exception) {
            if (count($errors) < 50) {
                $errors[] = $exception->getMessage();
            } elseif (count($errors) === 50) {
                $errors[] = 'Further row errors were omitted.';
            }
        }
    }

    return ['kind' => $kind, 'rows' => $rows, 'errors' => $errors];
}

function clear_csv_import_preview(): void
{
    unset($_SESSION['csv_import_preview'], $_SESSION['csv_import_errors']);
}

/** @return array<string, mixed>|null */
function csv_import_preview_for_page(): ?array
{
    $preview = $_SESSION['csv_import_preview'] ?? null;
    if (!is_array($preview)) {
        return null;
    }
    $expires = (int) ($preview['expires'] ?? 0);
    if ($expires < time() || (int) ($preview['household_id'] ?? 0) !== current_household_id()) {
        clear_csv_import_preview();

        return null;
    }

    return $preview;
}

/** @return list<string> */
function csv_import_errors_for_page(): array
{
    $errors = $_SESSION['csv_import_errors'] ?? [];
    if (!is_array($errors)) {
        return [];
    }

    $out = [];
    foreach ($errors as $error) {
        if (is_string($error) && $error !== '') {
            $out[] = $error;
        }
    }

    return $out;
}

function preview_household_csv_import(): void
{
    $householdId = current_household_id();
    $parsed = csv_preview_from_contents(csv_uploaded_contents(), $householdId);
    clear_csv_import_preview();
    if ($parsed['errors'] !== []) {
        $_SESSION['csv_import_errors'] = $parsed['errors'];
        throw new InvalidArgumentException(
            'This file was not imported. Fix the ' . count($parsed['errors']) . ' problem'
            . (count($parsed['errors']) === 1 ? '' : 's') . ' listed below.'
        );
    }
    if ($parsed['rows'] === []) {
        throw new InvalidArgumentException('That CSV has no data rows to import.');
    }

    $_SESSION['csv_import_preview'] = [
        'token' => bin2hex(random_bytes(16)),
        'kind' => $parsed['kind'],
        'rows' => $parsed['rows'],
        'household_id' => $householdId,
        'expires' => time() + CSV_IMPORT_PREVIEW_TTL,
    ];
}

function household_transaction_exists(
    int $householdId,
    string $date,
    string $type,
    string $description,
    float $amount,
    int $categoryId,
    string $notes
): bool {
    $stmt = db()->prepare(
        'SELECT id FROM transactions
         WHERE household_id = ? AND transaction_date = ? AND type = ? AND description = ?
           AND amount = ? AND category_id = ? AND IFNULL(notes, \'\') = ?
         LIMIT 1'
    );
    $stmt->execute([$householdId, $date, $type, $description, $amount, $categoryId, $notes]);

    return (int) $stmt->fetchColumn() > 0;
}

function household_recurring_exists(
    int $householdId,
    string $type,
    string $description,
    float $amount,
    int $categoryId,
    string $frequency,
    int $interval,
    string $startDate
): bool {
    $stmt = db()->prepare(
        'SELECT id FROM recurring_entries
         WHERE household_id = ? AND type = ? AND description = ? AND amount = ?
           AND category_id = ? AND frequency = ? AND interval_count = ? AND start_date = ?
         LIMIT 1'
    );
    $stmt->execute([
        $householdId, $type, $description, $amount, $categoryId, $frequency, $interval, $startDate,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function confirm_household_csv_import(string $token): array
{
    $preview = csv_import_preview_for_page();
    if ($preview === null || !hash_equals((string) ($preview['token'] ?? ''), $token)) {
        throw new InvalidArgumentException('That import preview expired. Check the CSV again.');
    }

    $householdId = current_household_id();
    $kind = (string) $preview['kind'];
    /** @var list<array<string, mixed>> $rows */
    $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
    $inserted = 0;
    $skipped = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($kind === 'recurring') {
            $stmt = $pdo->prepare(
                'INSERT INTO recurring_entries
                    (household_id, type, description, amount, category_id, frequency, interval_count,
                     start_date, next_due_date, end_date, notes, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($rows as $row) {
                $categoryId = (int) $row['category_id'];
                if (!category_belongs_to_type($categoryId, (string) $row['type'])) {
                    throw new InvalidArgumentException('A category in this import is no longer valid. Check the CSV again.');
                }
                if (household_recurring_exists(
                    $householdId,
                    (string) $row['type'],
                    (string) $row['description'],
                    (float) $row['amount'],
                    $categoryId,
                    (string) $row['frequency'],
                    (int) $row['interval'],
                    (string) $row['start_date']
                )) {
                    $skipped++;
                    continue;
                }
                $stmt->execute([
                    $householdId,
                    $row['type'],
                    $row['description'],
                    $row['amount'],
                    $categoryId,
                    $row['frequency'],
                    $row['interval'],
                    $row['start_date'],
                    $row['next_due'],
                    $row['end_date'] ?: null,
                    $row['notes'] !== '' ? $row['notes'] : null,
                    !empty($row['active']) ? 1 : 0,
                ]);
                $inserted++;
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO transactions
                    (household_id, type, description, amount, category_id, transaction_date, notes, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, \'manual\')'
            );
            foreach ($rows as $row) {
                $categoryId = (int) $row['category_id'];
                if (!category_belongs_to_type($categoryId, (string) $row['type'])) {
                    throw new InvalidArgumentException('A category in this import is no longer valid. Check the CSV again.');
                }
                $notes = (string) ($row['notes'] ?? '');
                if (household_transaction_exists(
                    $householdId,
                    (string) $row['date'],
                    (string) $row['type'],
                    (string) $row['description'],
                    (float) $row['amount'],
                    $categoryId,
                    $notes
                )) {
                    $skipped++;
                    continue;
                }
                $stmt->execute([
                    $householdId,
                    $row['type'],
                    $row['description'],
                    $row['amount'],
                    $categoryId,
                    $row['date'],
                    $notes !== '' ? $notes : null,
                ]);
                $inserted++;
            }
        }
        bump_household_state($pdo, $householdId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    if ($kind === 'recurring' && $inserted > 0) {
        materialise_due_recurring_entries(null, $householdId);
    }

    log_household_activity(
        $householdId,
        current_actor_user_id(),
        'csv_imported',
        'Imported ' . $inserted . ' ' . ($kind === 'recurring' ? 'recurring entries' : 'transactions')
        . ($skipped > 0 ? ' (' . $skipped . ' already present)' : '')
    );
    clear_csv_import_preview();

    return ['kind' => $kind, 'inserted' => $inserted, 'skipped' => $skipped];
}
