<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $key, mixed $default = null): mixed
{
    global $config;

    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function db(): PDO
{
    return Database::connect(config('database'));
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('Your session expired. Please go back, refresh the page and try again.');
    }
}

function redirect(string $page, array $query = []): never
{
    $query = array_merge(['page' => $page], $query);
    header('Location: ?' . http_build_query($query));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** @return array{type:string,message:string}|null */
function pull_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($message) ? $message : null;
}

function money(float|string|int $amount): string
{
    $symbol = (string) config('app.currency_symbol', '£');
    return $symbol . number_format((float) $amount, 2);
}

function valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function month_range(?string $month): array
{
    if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: new DateTimeImmutable('first day of this month');
    $end = $start->modify('last day of this month');

    return [$start->format('Y-m-d'), $end->format('Y-m-d'), $start->format('Y-m')];
}

const HOUSEHOLD_PUBLIC_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const HOUSEHOLD_PUBLIC_CODE_LENGTH = 16;
const HOUSEHOLD_PUBLIC_CODE_ATTEMPTS = 12;

function generate_household_public_code(): string
{
    $alphabet = HOUSEHOLD_PUBLIC_CODE_ALPHABET;
    $maxIndex = strlen($alphabet) - 1;
    $raw = '';
    for ($i = 0; $i < HOUSEHOLD_PUBLIC_CODE_LENGTH; $i++) {
        $raw .= $alphabet[random_int(0, $maxIndex)];
    }

    return substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4) . '-' . substr($raw, 12, 4);
}

function valid_household_public_code(string $code): bool
{
    return (bool) preg_match(
        '/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}(?:-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}){3}$/',
        $code
    );
}

function is_household_public_code_collision(PDOException $exception): bool
{
    $sqlState = (string) $exception->getCode();
    $message = $exception->getMessage();

    return $sqlState === '23000' && str_contains($message, 'public_code');
}

function allocate_household_public_code(PDO $pdo): string
{
    for ($attempt = 0; $attempt < HOUSEHOLD_PUBLIC_CODE_ATTEMPTS; $attempt++) {
        $code = generate_household_public_code();
        $stmt = $pdo->prepare('SELECT id FROM households WHERE public_code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() === false) {
            return $code;
        }
    }

    throw new RuntimeException('Could not allocate a household ID.');
}

function assign_household_public_code(PDO $pdo, int $householdId): string
{
    $existing = $pdo->prepare('SELECT public_code FROM households WHERE id = ?');
    $existing->execute([$householdId]);
    $current = $existing->fetchColumn();
    if (is_string($current) && valid_household_public_code($current)) {
        return $current;
    }

    for ($attempt = 0; $attempt < HOUSEHOLD_PUBLIC_CODE_ATTEMPTS; $attempt++) {
        $code = allocate_household_public_code($pdo);
        try {
            $stmt = $pdo->prepare(
                'UPDATE households SET public_code = ?
                 WHERE id = ? AND (public_code IS NULL OR public_code = \'\')'
            );
            $stmt->execute([$code, $householdId]);
        } catch (PDOException $exception) {
            if (!is_household_public_code_collision($exception)) {
                throw $exception;
            }
            continue;
        }

        $existing->execute([$householdId]);
        $current = $existing->fetchColumn();
        if (is_string($current) && valid_household_public_code($current)) {
            return $current;
        }
    }

    throw new RuntimeException('Could not allocate a household ID.');
}

function current_household_id(): int
{
    $user = current_user();
    if ($user === null || empty($user['household_id'])) {
        throw new RuntimeException('A signed-in household is required.');
    }

    return (int) $user['household_id'];
}

function bump_household_state(PDO $pdo, int $householdId): void
{
    if ($householdId < 1) {
        return;
    }

    $stmt = $pdo->prepare('UPDATE households SET state_version = state_version + 1 WHERE id = ?');
    $stmt->execute([$householdId]);
}

function household_state_version(?int $householdId = null): int
{
    $householdId = $householdId ?? current_household_id();
    $stmt = db()->prepare('SELECT state_version FROM households WHERE id = ?');
    $stmt->execute([$householdId]);

    return (int) $stmt->fetchColumn();
}

/** @return list<array{0:string,1:string,2:string,3:int}> */
function starter_categories(): array
{
    return [
        ['Salary', 'income', '#c7f36b', 10],
        ['Freelance', 'income', '#6ce5d4', 20],
        ['Benefits', 'income', '#8d83ff', 30],
        ['Investment', 'income', '#70a1ff', 40],
        ['Other income', 'income', '#a8b3c2', 90],
        ['Housing', 'expense', '#8d83ff', 10],
        ['Groceries', 'expense', '#6ce5d4', 20],
        ['Utilities', 'expense', '#70a1ff', 30],
        ['Transport', 'expense', '#ffc857', 40],
        ['Health', 'expense', '#ff826b', 50],
        ['Entertainment', 'expense', '#d176ff', 60],
        ['Subscriptions', 'expense', '#73d2de', 70],
        ['Other expense', 'expense', '#a8b3c2', 90],
    ];
}

function seed_household_categories(PDO $pdo, int $householdId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO categories (household_id, name, type, colour, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach (starter_categories() as [$name, $type, $colour, $sortOrder]) {
        $stmt->execute([$householdId, $name, $type, $colour, $sortOrder]);
    }
}

/** @return array<int, array<string,mixed>> */
function categories(?string $type = null): array
{
    $householdId = current_household_id();
    if ($type !== null) {
        $stmt = db()->prepare(
            'SELECT id, name, type, colour FROM categories
             WHERE household_id = ? AND type = ?
             ORDER BY sort_order, name'
        );
        $stmt->execute([$householdId, $type]);
        return $stmt->fetchAll();
    }

    $stmt = db()->prepare(
        'SELECT id, name, type, colour FROM categories
         WHERE household_id = ?
         ORDER BY type DESC, sort_order, name'
    );
    $stmt->execute([$householdId]);
    return $stmt->fetchAll();
}

function category_belongs_to_type(int $categoryId, string $type): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM categories WHERE id = ? AND type = ? AND household_id = ?'
    );
    $stmt->execute([$categoryId, $type, current_household_id()]);
    return (int) $stmt->fetchColumn() === 1;
}

function household_owns_transaction(int $id): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM transactions WHERE id = ? AND household_id = ?');
    $stmt->execute([$id, current_household_id()]);
    return (int) $stmt->fetchColumn() === 1;
}

function household_owns_recurring(int $id): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM recurring_entries WHERE id = ? AND household_id = ?');
    $stmt->execute([$id, current_household_id()]);
    return (int) $stmt->fetchColumn() === 1;
}

function materialise_due_recurring_entries(?string $throughDate = null, ?int $householdId = null): int
{
    $throughDate ??= date('Y-m-d');
    $pdo = db();
    $sql = 'SELECT * FROM recurring_entries
            WHERE active = 1 AND next_due_date <= ?
              AND (end_date IS NULL OR next_due_date <= end_date)';
    $params = [$throughDate];
    if ($householdId !== null) {
        $sql .= ' AND household_id = ?';
        $params[] = $householdId;
    }
    $sql .= ' ORDER BY next_due_date, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll();
    $created = 0;

    foreach ($entries as $entry) {
        $pdo->beginTransaction();
        try {
            $cursor = (string) $entry['next_due_date'];
            $entryHouseholdId = (int) $entry['household_id'];
            $safety = 0;
            while ($cursor <= $throughDate && (!$entry['end_date'] || $cursor <= $entry['end_date'])) {
                $insert = $pdo->prepare(
                    'INSERT IGNORE INTO transactions
                     (household_id, type, description, amount, category_id, transaction_date, notes, source, recurring_entry_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, \'recurring\', ?)'
                );
                $insert->execute([
                    $entryHouseholdId,
                    $entry['type'],
                    $entry['description'],
                    $entry['amount'],
                    $entry['category_id'],
                    $cursor,
                    $entry['notes'],
                    $entry['id'],
                ]);
                $created += $insert->rowCount();

                $cursor = Recurrence::nextDate($cursor, (string) $entry['frequency'], (int) $entry['interval_count']);
                if (++$safety >= 500) {
                    throw new RuntimeException('A recurring entry produced too many transactions at once.');
                }
            }

            $update = $pdo->prepare(
                'UPDATE recurring_entries
                 SET next_due_date = ?,
                     active = CASE WHEN end_date IS NOT NULL AND ? > end_date THEN 0 ELSE active END,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND household_id = ?'
            );
            $update->execute([$cursor, $cursor, $entry['id'], $entryHouseholdId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Recurring entry materialisation failed: ' . $exception->getMessage());
        }
    }

    return $created;
}

function old(string $key, string $fallback = ''): string
{
    return e(isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : $fallback);
}

/**
 * @return array{0:string,1:string,2:?string,3:bool}
 */
function statement_date_range(mixed $fromInput, mixed $toInput): array
{
    $defaultFrom = date('Y-m-01');
    $defaultTo = date('Y-m-d');
    $from = is_string($fromInput) ? $fromInput : $defaultFrom;
    $to = is_string($toInput) ? $toInput : $defaultTo;
    $rangeNote = null;

    if (!valid_date($from)) {
        $from = $defaultFrom;
    }
    if (!valid_date($to)) {
        $to = $defaultTo;
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
        $rangeNote = 'The start date was after the end date, so the two dates were swapped.';
    }

    return [$from, $to, $rangeNote, $from === $defaultFrom && $to === $defaultTo];
}

/**
 * @return array{
 *   from:string,to:string,from_label:string,to_label:string,
 *   income:float,expense:float,balance:float,entry_count:int,list_limit:int,truncated:bool,
 *   household_name:string,income_categories:list<array<string,mixed>>,
 *   expense_categories:list<array<string,mixed>>,transactions:list<array<string,mixed>>
 * }
 */
function load_statement(string $from, string $to): array
{
    $householdId = current_household_id();
    $listLimit = 250;
    $user = current_user();

    $summaryStmt = db()->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN type = \'income\' THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN type = \'expense\' THEN amount ELSE 0 END), 0) AS expense,
            COUNT(*) AS entry_count
         FROM transactions
         WHERE household_id = ? AND transaction_date BETWEEN ? AND ?'
    );
    $summaryStmt->execute([$householdId, $from, $to]);
    $summary = $summaryStmt->fetch() ?: ['income' => 0, 'expense' => 0, 'entry_count' => 0];
    $income = (float) $summary['income'];
    $expense = (float) $summary['expense'];
    $entryCount = (int) $summary['entry_count'];

    $categoryStmt = db()->prepare(
        'SELECT c.name, c.colour, t.type, SUM(t.amount) AS total
         FROM transactions t
         JOIN categories c ON c.id = t.category_id AND c.household_id = t.household_id
         WHERE t.household_id = ? AND t.transaction_date BETWEEN ? AND ?
         GROUP BY c.id, c.name, c.colour, t.type
         ORDER BY t.type DESC, total DESC, c.name'
    );
    $categoryStmt->execute([$householdId, $from, $to]);
    $incomeCategories = [];
    $expenseCategories = [];
    foreach ($categoryStmt->fetchAll() as $row) {
        if ($row['type'] === 'income') {
            $incomeCategories[] = $row;
        } else {
            $expenseCategories[] = $row;
        }
    }

    $listStmt = db()->prepare(
        'SELECT t.*, c.name AS category_name, c.colour AS category_colour
         FROM transactions t
         JOIN categories c ON c.id = t.category_id AND c.household_id = t.household_id
         WHERE t.household_id = ? AND t.transaction_date BETWEEN ? AND ?
         ORDER BY t.transaction_date DESC, t.id DESC
         LIMIT ' . $listLimit
    );
    $listStmt->execute([$householdId, $from, $to]);

    return [
        'from' => $from,
        'to' => $to,
        'from_label' => (new DateTimeImmutable($from))->format('j M Y'),
        'to_label' => (new DateTimeImmutable($to))->format('j M Y'),
        'income' => $income,
        'expense' => $expense,
        'balance' => $income - $expense,
        'entry_count' => $entryCount,
        'list_limit' => $listLimit,
        'truncated' => $entryCount > $listLimit,
        'household_name' => (string) ($user['household_name'] ?? 'Household'),
        'income_categories' => $incomeCategories,
        'expense_categories' => $expenseCategories,
        'transactions' => $listStmt->fetchAll(),
    ];
}
