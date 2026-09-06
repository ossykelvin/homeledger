<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;

function categories_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $message . "\n");
}

function categories_insert_user(PDO $pdo, int $householdId, string $email, string $name): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (household_id, login, display_name, password_hash) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$householdId, $email, $name, 'test-hash-not-for-login']);

    return (int) $pdo->lastInsertId();
}

function categories_exception_message(callable $callback): string
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }

    return '';
}

$_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];

$pdo = db();
$stamp = bin2hex(random_bytes(4));
$householdIds = [];
$userIds = [];

try {
    $pdo->beginTransaction();

    $household1Name = $pdo->query('SELECT name FROM households WHERE id = 1')->fetchColumn();
    $household1Cats = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE household_id = 1')->fetchColumn();

    $insertHousehold = $pdo->prepare('INSERT INTO households (name, public_code, state_version) VALUES (?, ?, 1)');
    $insertHousehold->execute(['Category Test ' . $stamp, allocate_household_public_code($pdo)]);
    $householdId = (int) $pdo->lastInsertId();
    $householdIds[] = $householdId;
    seed_household_categories($pdo, $householdId);

    $ownerEmail = 'hl-cat-owner-' . $stamp . '@example.test';
    $memberEmail = 'hl-cat-member-' . $stamp . '@example.test';
    $ownerId = categories_insert_user($pdo, $householdId, $ownerEmail, 'Owner Cat');
    $memberId = categories_insert_user($pdo, $householdId, $memberEmail, 'Member Cat');
    $userIds = [$ownerId, $memberId];
    $setOwner = $pdo->prepare('UPDATE households SET owner_user_id = ? WHERE id = ?');
    $setOwner->execute([$ownerId, $householdId]);

    categories_assert($householdId !== 1, 'Throwaway household must not reuse household 1.');
    categories_assert(household_state_version($householdId) === 1, 'New household state version should start at 1.');

    $_SESSION['user_id'] = $ownerId;
    $starter = $pdo->prepare('SELECT id, name, type FROM categories WHERE household_id = ? AND name = ? AND type = ?');
    $starter->execute([$householdId, 'Salary', 'income']);
    $salary = $starter->fetch();
    categories_assert(is_array($salary), 'Seeded Salary category should exist.');

    $before = household_state_version($householdId);
    update_household_category((int) $salary['id'], 'Pay', 'income', '#c7f36b');
    $renamed = find_household_category((int) $salary['id']);
    categories_assert(is_array($renamed) && $renamed['name'] === 'Pay', 'Owner should be able to rename a seeded category.');
    categories_assert(household_state_version($householdId) === $before + 1, 'Renaming a category should bump household state.');

    $createdId = create_household_category('Childcare', 'expense', '#ff826b');
    $created = find_household_category($createdId);
    categories_assert(is_array($created) && $created['name'] === 'Childcare', 'Owner should be able to add a category.');
    categories_assert($created['type'] === 'expense', 'New category should keep the chosen type.');
    categories_assert(household_state_version($householdId) === $before + 2, 'Adding a category should bump household state.');

    $dupMessage = categories_exception_message(static function (): void {
        create_household_category('Childcare', 'expense', '#ff826b');
    });
    categories_assert(
        str_contains($dupMessage, 'already exists'),
        'Duplicate name and type in the same household should be rejected.'
    );

    update_household_category($createdId, 'Childcare', 'income', '#c7f36b');
    $flipped = find_household_category($createdId);
    categories_assert(is_array($flipped) && $flipped['type'] === 'income', 'Unused category type should be editable.');

    $pdo->prepare(
        'INSERT INTO transactions (household_id, type, description, amount, category_id, transaction_date, source)
         VALUES (?, ?, ?, ?, ?, CURDATE(), \'manual\')'
    )->execute([$householdId, 'income', 'Used category row', 10.00, $createdId]);

    $typeLocked = categories_exception_message(static function () use ($createdId): void {
        update_household_category($createdId, 'Childcare', 'expense', '#ff826b');
    });
    categories_assert(
        str_contains($typeLocked, 'type cannot be changed'),
        'Used category type should be locked.'
    );

    $deleteUsed = categories_exception_message(static function () use ($createdId): void {
        delete_household_category($createdId);
    });
    categories_assert(
        str_contains($deleteUsed, 'cannot be deleted'),
        'Used categories should refuse delete.'
    );

    $spareId = create_household_category('Spare label', 'expense', '#a8b3c2');
    $beforeDelete = household_state_version($householdId);
    delete_household_category($spareId);
    categories_assert(find_household_category($spareId) === null, 'Unused category should delete.');
    categories_assert(household_state_version($householdId) === $beforeDelete + 1, 'Deleting a category should bump household state.');

    $_SESSION['user_id'] = $memberId;
    $memberCreate = categories_exception_message(static function (): void {
        create_household_category('Forged', 'expense', '#ff826b');
    });
    categories_assert(
        str_contains($memberCreate, 'Only the household owner can manage categories.'),
        'Members must not create categories.'
    );

    $memberUpdate = categories_exception_message(static function () use ($createdId): void {
        update_household_category($createdId, 'Forged name', 'income', '#c7f36b');
    });
    categories_assert(
        str_contains($memberUpdate, 'Only the household owner can manage categories.'),
        'Members must not edit categories.'
    );

    $memberDelete = categories_exception_message(static function () use ($createdId): void {
        delete_household_category($createdId);
    });
    categories_assert(
        str_contains($memberDelete, 'Only the household owner can manage categories.'),
        'Members must not delete categories.'
    );

    $afterHousehold1Name = $pdo->query('SELECT name FROM households WHERE id = 1')->fetchColumn();
    $afterHousehold1Cats = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE household_id = 1')->fetchColumn();
    categories_assert($afterHousehold1Name === $household1Name, 'Household 1 name must stay untouched.');
    categories_assert($afterHousehold1Cats === $household1Cats, 'Household 1 categories must stay untouched.');

    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    categories_assert(false, 'Category test setup failed: ' . $exception->getMessage());
}

$_SESSION = [];

if ($failures > 0) {
    fwrite(STDERR, "Category tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Category tests passed.\n");
exit(0);
