<?php

declare(strict_types=1);

function is_category_name_type_collision(PDOException $exception): bool
{
    $sqlState = (string) $exception->getCode();
    $message = $exception->getMessage();

    return $sqlState === '23000' && str_contains($message, 'categories_household_name_type_unique');
}

function normalize_category_name(string $name): string
{
    $name = trim($name);
    if ($name === '' || text_length($name) > 80) {
        throw new InvalidArgumentException('Enter a category name of up to 80 characters.');
    }

    return $name;
}

function normalize_category_type(string $type): string
{
    if (!in_array($type, ['income', 'expense'], true)) {
        throw new InvalidArgumentException('Choose income or expense.');
    }

    return $type;
}

function normalize_category_colour(string $colour, string $type): string
{
    $colour = trim($colour);
    if ($colour === '') {
        return $type === 'income' ? '#c7f36b' : '#8d83ff';
    }
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $colour)) {
        throw new InvalidArgumentException('Choose a valid colour.');
    }

    return strtolower($colour);
}

/** @return array<string, mixed>|null */
function find_household_category(int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, household_id, name, type, colour, sort_order
         FROM categories
         WHERE id = ? AND household_id = ?'
    );
    $stmt->execute([$id, current_household_id()]);
    $category = $stmt->fetch();

    return is_array($category) ? $category : null;
}

function household_owns_category(int $id): bool
{
    return find_household_category($id) !== null;
}

/** @return array{transactions:int,recurring:int} */
function category_usage_counts(int $categoryId): array
{
    $householdId = current_household_id();
    $transactions = db()->prepare(
        'SELECT COUNT(*) FROM transactions WHERE category_id = ? AND household_id = ?'
    );
    $transactions->execute([$categoryId, $householdId]);
    $recurring = db()->prepare(
        'SELECT COUNT(*) FROM recurring_entries WHERE category_id = ? AND household_id = ?'
    );
    $recurring->execute([$categoryId, $householdId]);

    return [
        'transactions' => (int) $transactions->fetchColumn(),
        'recurring' => (int) $recurring->fetchColumn(),
    ];
}

function category_is_used(int $categoryId): bool
{
    $usage = category_usage_counts($categoryId);

    return $usage['transactions'] > 0 || $usage['recurring'] > 0;
}

function next_category_sort_order(int $householdId, string $type): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM categories WHERE household_id = ? AND type = ?'
    );
    $stmt->execute([$householdId, $type]);

    return (int) $stmt->fetchColumn();
}

function create_household_category(string $name, string $type, string $colour = ''): int
{
    assert_household_owner('Only the household owner can manage categories.');

    $name = normalize_category_name($name);
    $type = normalize_category_type($type);
    $colour = normalize_category_colour($colour, $type);
    $householdId = current_household_id();
    $sortOrder = next_category_sort_order($householdId, $type);

    try {
        $stmt = db()->prepare(
            'INSERT INTO categories (household_id, name, type, colour, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$householdId, $name, $type, $colour, $sortOrder]);
    } catch (PDOException $exception) {
        if (is_category_name_type_collision($exception)) {
            throw new InvalidArgumentException(
                'A category with that name and type already exists in this household.'
            );
        }
        throw $exception;
    }

    $id = (int) db()->lastInsertId();
    bump_household_state(db(), $householdId);

    return $id;
}

function update_household_category(int $id, string $name, string $type, string $colour = ''): void
{
    assert_household_owner('Only the household owner can manage categories.');

    $existing = find_household_category($id);
    if ($existing === null) {
        throw new InvalidArgumentException('Category not found.');
    }

    $name = normalize_category_name($name);
    $type = normalize_category_type($type);
    $colour = normalize_category_colour($colour, $type);
    $currentType = (string) $existing['type'];

    if ($type !== $currentType && category_is_used($id)) {
        throw new InvalidArgumentException(
            'This category is used by transactions or recurring entries, so its type cannot be changed.'
        );
    }

    $householdId = current_household_id();
    try {
        $stmt = db()->prepare(
            'UPDATE categories
             SET name = ?, type = ?, colour = ?
             WHERE id = ? AND household_id = ?'
        );
        $stmt->execute([$name, $type, $colour, $id, $householdId]);
    } catch (PDOException $exception) {
        if (is_category_name_type_collision($exception)) {
            throw new InvalidArgumentException(
                'A category with that name and type already exists in this household.'
            );
        }
        throw $exception;
    }

    bump_household_state(db(), $householdId);
}

function delete_household_category(int $id): void
{
    assert_household_owner('Only the household owner can manage categories.');

    if (find_household_category($id) === null) {
        throw new InvalidArgumentException('Category not found.');
    }

    $usage = category_usage_counts($id);
    if ($usage['transactions'] > 0 || $usage['recurring'] > 0) {
        throw new InvalidArgumentException(
            'This category is used by transactions or recurring entries, so it cannot be deleted. Recategorise those entries first.'
        );
    }

    $householdId = current_household_id();
    $stmt = db()->prepare('DELETE FROM categories WHERE id = ? AND household_id = ?');
    $stmt->execute([$id, $householdId]);
    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('Category not found.');
    }

    bump_household_state(db(), $householdId);
}

/** @return list<array<string, mixed>> */
function household_categories_with_usage(): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.name, c.type, c.colour, c.sort_order,
                (SELECT COUNT(*) FROM transactions t
                  WHERE t.category_id = c.id AND t.household_id = c.household_id) AS transaction_count,
                (SELECT COUNT(*) FROM recurring_entries r
                  WHERE r.category_id = c.id AND r.household_id = c.household_id) AS recurring_count
         FROM categories c
         WHERE c.household_id = ?
         ORDER BY c.type DESC, c.sort_order, c.name'
    );
    $stmt->execute([current_household_id()]);

    return $stmt->fetchAll() ?: [];
}

/** @param array<string, mixed> $row */
function category_usage_label(array $row): string
{
    $transactions = (int) ($row['transaction_count'] ?? 0);
    $recurring = (int) ($row['recurring_count'] ?? 0);
    if ($transactions === 0 && $recurring === 0) {
        return 'Unused';
    }

    $parts = [];
    if ($transactions > 0) {
        $parts[] = $transactions . ' transaction' . ($transactions === 1 ? '' : 's');
    }
    if ($recurring > 0) {
        $parts[] = $recurring . ' recurring';
    }

    return implode(', ', $parts);
}
