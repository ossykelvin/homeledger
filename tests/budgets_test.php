<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;

function budgets_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $message . "\n");
}

function budgets_exception_message(callable $callback): string
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }

    return '';
}

function budgets_insert_user(PDO $pdo, int $householdId, string $email, string $name): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (household_id, login, display_name, password_hash, email_verified_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$householdId, $email, $name, 'test-hash-not-for-login']);

    return (int) $pdo->lastInsertId();
}

function budgets_cleanup(PDO $pdo, array $userIds, array $householdIds): void
{
    foreach ($userIds as $userId) {
        if ($userId < 1) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT household_id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $householdId = (int) $stmt->fetchColumn();
        if ($householdId > 0 && $householdId !== 1) {
            $pdo->prepare('UPDATE households SET owner_user_id = NULL WHERE id = ? AND owner_user_id = ?')
                ->execute([$householdId, $userId]);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
    foreach ($householdIds as $householdId) {
        if ($householdId < 2) {
            continue;
        }
        $pdo->prepare('UPDATE households SET owner_user_id = NULL WHERE id = ?')->execute([$householdId]);
        wipe_household_dependent_rows($pdo, $householdId);
        $pdo->prepare('DELETE FROM users WHERE household_id = ?')->execute([$householdId]);
        $pdo->prepare('DELETE FROM households WHERE id = ?')->execute([$householdId]);
    }
}

$root = dirname(__DIR__);
$schema = (string) file_get_contents($root . '/database/schema.sql');
$schemaImport = (string) file_get_contents($root . '/database/schema_import.sql');
$migration = (string) file_get_contents($root . '/database/migrations/010_budgets_activity.sql');
$wipe = (string) file_get_contents($root . '/app/AccountDelete.php');

budgets_assert(str_contains($migration, 'category_budgets'), 'Migration 010 should create category_budgets.');
budgets_assert(str_contains($migration, 'household_activity'), 'Migration 010 should create household_activity.');
budgets_assert(str_contains($migration, 'household_spend_alerts'), 'Migration 010 should create household_spend_alerts.');
budgets_assert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS category_budgets'), 'schema.sql should include category_budgets.');
budgets_assert(str_contains($schemaImport, 'CREATE TABLE IF NOT EXISTS household_spend_alerts'), 'schema_import.sql should include spend alerts.');
budgets_assert(str_contains($wipe, 'DELETE FROM category_budgets'), 'Account delete wipe should remove category budgets.');
budgets_assert(str_contains($wipe, 'DELETE FROM household_activity'), 'Account delete wipe should remove activity.');
budgets_assert(str_contains($wipe, 'DELETE FROM household_spend_alerts'), 'Account delete wipe should remove spend alerts.');
budgets_assert(unusual_spend_threshold_met(62.50, 50.00), '62.50 vs 50 should count as unusual spend.');
budgets_assert(!unusual_spend_threshold_met(62.00, 50.00), 'Below 125% should not count as unusual spend.');
budgets_assert(!unusual_spend_threshold_met(100.00, 40.00), 'Previous spend under 50 should not count as unusual.');
budgets_assert(
    monthly_budget_for_period(310.00, '2026-01-01', '2026-01-31') === 310.00,
    'A full calendar month should use the monthly budget as-is.'
);
budgets_assert(
    monthly_budget_for_period(310.00, '2026-01-01', '2026-01-15') === 150.00,
    'A half-month range should prorate the monthly budget by days.'
);

$alertHtml = spend_alert_payload(
    'cat:1:2026-09',
    'Groceries is high in September 2026',
    'Groceries spending is ' . money(80) . ' in September 2026.',
    'Groceries spending is high.',
    ['This has reached the ' . money(50) . ' monthly budget.'],
    ['This has reached the ' . money(50) . ' monthly budget.']
);
budgets_assert(!str_contains($alertHtml['plain'], '—') && !str_contains($alertHtml['extra'], '—'), 'Spend alert copy should not use an em dash.');

$_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];
$pdo = db();
$stamp = bin2hex(random_bytes(4));
$createdHouseholds = [];
$createdUsers = [];
$household1Tx = (int) $pdo->query('SELECT COUNT(*) FROM transactions WHERE household_id = 1')->fetchColumn();

try {
    $insertHousehold = $pdo->prepare('INSERT INTO households (name, public_code, state_version) VALUES (?, ?, 1)');
    $insertHousehold->execute(['Budget Test A ' . $stamp, allocate_household_public_code($pdo)]);
    $householdA = (int) $pdo->lastInsertId();
    $createdHouseholds[] = $householdA;
    seed_household_categories($pdo, $householdA);

    $insertHousehold->execute(['Budget Test B ' . $stamp, allocate_household_public_code($pdo)]);
    $householdB = (int) $pdo->lastInsertId();
    $createdHouseholds[] = $householdB;
    seed_household_categories($pdo, $householdB);

    budgets_assert($householdA !== 1 && $householdB !== 1, 'Throwaway households must not reuse household 1.');

    $ownerA = budgets_insert_user($pdo, $householdA, 'hl-bud-owner-' . $stamp . '@example.test', 'Owner A');
    $memberA = budgets_insert_user($pdo, $householdA, 'hl-bud-member-' . $stamp . '@example.test', 'Member A');
    $ownerB = budgets_insert_user($pdo, $householdB, 'hl-bud-other-' . $stamp . '@example.test', 'Owner B');
    $createdUsers = [$ownerA, $memberA, $ownerB];
    $pdo->prepare('UPDATE households SET owner_user_id = ? WHERE id = ?')->execute([$ownerA, $householdA]);
    $pdo->prepare('UPDATE households SET owner_user_id = ? WHERE id = ?')->execute([$ownerB, $householdB]);

    $catA = $pdo->prepare('SELECT id FROM categories WHERE household_id = ? AND name = ? AND type = ?');
    $catA->execute([$householdA, 'Groceries', 'expense']);
    $groceriesA = (int) $catA->fetchColumn();
    $catA->execute([$householdA, 'Salary', 'income']);
    $salaryA = (int) $catA->fetchColumn();
    $catA->execute([$householdB, 'Groceries', 'expense']);
    $groceriesB = (int) $catA->fetchColumn();
    budgets_assert($groceriesA > 0 && $salaryA > 0 && $groceriesB > 0, 'Seeded categories should exist.');

    $_SESSION['user_id'] = $ownerA;
    save_category_budget($groceriesA, '80.00');
    $saved = household_category_budgets($householdA);
    budgets_assert(
        isset($saved[$groceriesA]) && abs($saved[$groceriesA] - 80.00) < 0.001,
        'Owner should be able to set an expense budget.'
    );
    budgets_assert(household_state_version($householdA) === 2, 'Saving a budget should bump household state.');

    $incomeBlocked = budgets_exception_message(static function () use ($salaryA): void {
        save_category_budget($salaryA, '100.00');
    });
    budgets_assert(str_contains($incomeBlocked, 'expense categories'), 'Income categories should reject budgets.');

    $_SESSION['user_id'] = $memberA;
    $memberBlocked = budgets_exception_message(static function () use ($groceriesA): void {
        save_category_budget($groceriesA, '10.00');
    });
    budgets_assert(
        str_contains($memberBlocked, 'Only the household owner can manage budgets.'),
        'Members must not set budgets.'
    );

    $_SESSION['user_id'] = $ownerB;
    $outsider = budgets_exception_message(static function () use ($groceriesA): void {
        save_category_budget($groceriesA, '999.00');
    });
    budgets_assert($outsider !== '', 'A budget save must stay inside the signed-in household.');
    $stillA = household_category_budgets($householdA);
    budgets_assert(abs(($stillA[$groceriesA] ?? 0) - 80.00) < 0.001, 'Household B must not change household A budgets.');

    $_SESSION['user_id'] = $ownerA;
    $thisMonthStart = date('Y-m-01');
    $thisMonthEnd = date('Y-m-t');
    $lastStart = (new DateTimeImmutable($thisMonthStart))->modify('first day of last month')->format('Y-m-d');
    $insertTx = $pdo->prepare(
        'INSERT INTO transactions (household_id, type, description, amount, category_id, transaction_date, source)
         VALUES (?, \'expense\', ?, ?, ?, ?, \'manual\')'
    );
    $insertTx->execute([$householdA, 'Groceries last month', 50.00, $groceriesA, $lastStart]);
    $insertTx->execute([$householdA, 'Groceries this month', 100.00, $groceriesA, $thisMonthStart]);

    $progress = household_budget_progress($thisMonthStart, $thisMonthEnd, $householdA);
    $groceriesProgress = null;
    foreach ($progress as $row) {
        if ((int) $row['id'] === $groceriesA) {
            $groceriesProgress = $row;
            break;
        }
    }
    budgets_assert(is_array($groceriesProgress), 'Budget progress should include the budgeted category.');
    budgets_assert($groceriesProgress['over'] === true, 'Spent at or over budget should warn.');
    budgets_assert(abs((float) $groceriesProgress['spent'] - 100.00) < 0.001, 'Progress spent should match this month.');

    $warnings = household_budget_warnings($thisMonthStart, $thisMonthEnd, $householdA);
    budgets_assert($warnings !== [], 'Dashboard warnings should include the overspent category.');

    $candidates = household_spend_alert_candidates($householdA, date('Y-m'));
    $keys = array_column($candidates, 'key');
    budgets_assert(
        in_array('cat:' . $groceriesA . ':' . date('Y-m'), $keys, true),
        'Unusual or over-budget spend should produce a category alert candidate.'
    );
    budgets_assert(
        in_array('month:' . date('Y-m'), $keys, true),
        'A high month vs last month should produce a month alert candidate.'
    );

    $alertKey = 'test:' . $stamp;
    budgets_assert(claim_household_spend_alert($householdA, $alertKey), 'First claim for an alert key should succeed.');
    budgets_assert(!claim_household_spend_alert($householdA, $alertKey), 'A second claim for the same key should be ignored.');

    $otherProgress = household_budget_progress($thisMonthStart, $thisMonthEnd, $householdB);
    budgets_assert($otherProgress === [], 'Household B should not see household A budgets.');

    save_category_budget($groceriesA, '');
    $cleared = household_category_budgets($householdA);
    budgets_assert(!isset($cleared[$groceriesA]), 'Blank amount should clear the budget.');
} catch (Throwable $exception) {
    budgets_assert(false, 'Budget tests failed: ' . $exception->getMessage());
} finally {
    $_SESSION = [];
    try {
        budgets_cleanup($pdo, $createdUsers, $createdHouseholds);
    } catch (Throwable $exception) {
        budgets_assert(false, 'Budget test cleanup failed: ' . $exception->getMessage());
    }
}

$afterHousehold1Tx = (int) $pdo->query('SELECT COUNT(*) FROM transactions WHERE household_id = 1')->fetchColumn();
budgets_assert($afterHousehold1Tx === $household1Tx, 'Household 1 money rows must stay untouched.');

if ($failures > 0) {
    fwrite(STDERR, "Budget tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Budget tests passed.\n");
exit(0);
