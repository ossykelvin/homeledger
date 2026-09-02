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
        http_response_code(419);
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

/** @return array<int, array<string,mixed>> */
function categories(?string $type = null): array
{
    if ($type !== null) {
        $stmt = db()->prepare('SELECT id, name, type, colour FROM categories WHERE type = ? ORDER BY sort_order, name');
        $stmt->execute([$type]);
        return $stmt->fetchAll();
    }

    return db()->query('SELECT id, name, type, colour FROM categories ORDER BY type DESC, sort_order, name')->fetchAll();
}

function category_belongs_to_type(int $categoryId, string $type): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND type = ?');
    $stmt->execute([$categoryId, $type]);
    return (int) $stmt->fetchColumn() === 1;
}

function materialise_due_recurring_entries(?string $throughDate = null): int
{
    $throughDate ??= date('Y-m-d');
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM recurring_entries
         WHERE active = 1 AND next_due_date <= ?
           AND (end_date IS NULL OR next_due_date <= end_date)
         ORDER BY next_due_date, id'
    );
    $stmt->execute([$throughDate]);
    $entries = $stmt->fetchAll();
    $created = 0;

    foreach ($entries as $entry) {
        $pdo->beginTransaction();
        try {
            $cursor = (string) $entry['next_due_date'];
            $safety = 0;
            while ($cursor <= $throughDate && (!$entry['end_date'] || $cursor <= $entry['end_date'])) {
                $insert = $pdo->prepare(
                    'INSERT IGNORE INTO transactions
                     (type, description, amount, category_id, transaction_date, notes, source, recurring_entry_id)
                     VALUES (?, ?, ?, ?, ?, ?, \'recurring\', ?)'
                );
                $insert->execute([
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
                 WHERE id = ?'
            );
            $update->execute([$cursor, $cursor, $entry['id']]);
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
