<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;

function portability_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $message . "\n");
}

function portability_exception_message(callable $callback): string
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }

    return '';
}

function portability_insert_user(PDO $pdo, int $householdId, string $email, string $name): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (household_id, login, display_name, password_hash, email_verified_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$householdId, $email, $name, password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT)]);

    return (int) $pdo->lastInsertId();
}

function portability_cleanup(PDO $pdo, array $userIds, array $householdIds): void
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
$csvSource = (string) file_get_contents($root . '/app/CsvTransfer.php');
$backupSource = (string) file_get_contents($root . '/app/HouseholdBackup.php');
$householdPage = (string) file_get_contents($root . '/templates/pages/household.php');
$actions = (string) file_get_contents($root . '/app/actions.php');
$bootstrap = (string) file_get_contents($root . '/app/bootstrap.php');
$sw = (string) file_get_contents($root . '/public/service-worker.js');

portability_assert(str_contains($bootstrap, 'CsvTransfer.php'), 'bootstrap should load CsvTransfer.php.');
portability_assert(str_contains($bootstrap, 'HouseholdBackup.php'), 'bootstrap should load HouseholdBackup.php.');
portability_assert(str_contains($actions, 'export_csv_transactions'), 'actions should export transactions CSV.');
portability_assert(str_contains($actions, 'preview_csv_import'), 'actions should dry-run CSV import.');
portability_assert(str_contains($actions, 'restore_household_backup'), 'actions should restore encrypted backups.');
portability_assert(str_contains($householdPage, 'export_csv_transactions'), 'Household should offer CSV export.');
portability_assert(str_contains($householdPage, 'preview_csv_import'), 'Household should offer CSV check.');
portability_assert(str_contains($householdPage, 'download_household_backup'), 'Household should offer encrypted backup.');
portability_assert(str_contains($householdPage, 'restore-backup-dialog'), 'Household should offer restore dialog.');
portability_assert(str_contains($sw, 'homeledger-shell-v24'), 'Service worker should bump after portability UI.');
portability_assert(!str_contains($csvSource, "\u{2014}") && !str_contains($backupSource, "\u{2014}"), 'Portability copy should not use an em dash.');
portability_assert(!str_contains($householdPage, "\u{2014}"), 'Household portability copy should not use an em dash.');
portability_assert(str_contains($csvSource, 'aes-256-gcm') === false, 'CSV module should not encrypt.');
portability_assert(str_contains($backupSource, 'aes-256-gcm'), 'Backup should use AES-256-GCM.');

$formula = csv_document(['Amount'], [['=1+1']]);
portability_assert(str_contains($formula, "'=1+1"), 'CSV export should neutralize formula cells.');

$_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];
$pdo = db();
$stamp = bin2hex(random_bytes(4));
$createdHouseholds = [];
$createdUsers = [];
$household1Tx = (int) $pdo->query('SELECT COUNT(*) FROM transactions WHERE household_id = 1')->fetchColumn();

try {
    $insertHousehold = $pdo->prepare('INSERT INTO households (name, public_code, state_version) VALUES (?, ?, 1)');
    $insertHousehold->execute(['Portability A ' . $stamp, allocate_household_public_code($pdo)]);
    $householdA = (int) $pdo->lastInsertId();
    $createdHouseholds[] = $householdA;
    seed_household_categories($pdo, $householdA);
    $insertHousehold->execute(['Portability B ' . $stamp, allocate_household_public_code($pdo)]);
    $householdB = (int) $pdo->lastInsertId();
    $createdHouseholds[] = $householdB;
    seed_household_categories($pdo, $householdB);

    portability_assert($householdA !== 1 && $householdB !== 1, 'Throwaway households must not reuse household 1.');

    $ownerA = portability_insert_user($pdo, $householdA, 'hl-port-a-' . $stamp . '@example.test', 'Owner A');
    $memberA = portability_insert_user($pdo, $householdA, 'hl-port-am-' . $stamp . '@example.test', 'Member A');
    $ownerB = portability_insert_user($pdo, $householdB, 'hl-port-b-' . $stamp . '@example.test', 'Owner B');
    $createdUsers = [$ownerA, $memberA, $ownerB];
    $pdo->prepare('UPDATE households SET owner_user_id = ? WHERE id = ?')->execute([$ownerA, $householdA]);
    $pdo->prepare('UPDATE households SET owner_user_id = ? WHERE id = ?')->execute([$ownerB, $householdB]);

    $cat = $pdo->prepare('SELECT id FROM categories WHERE household_id = ? AND name = ? AND type = ?');
    $cat->execute([$householdA, 'Groceries', 'expense']);
    $groceriesA = (int) $cat->fetchColumn();
    $cat->execute([$householdA, 'Salary', 'income']);
    $salaryA = (int) $cat->fetchColumn();
    $cat->execute([$householdB, 'Groceries', 'expense']);
    $groceriesB = (int) $cat->fetchColumn();
    portability_assert($groceriesA > 0 && $salaryA > 0 && $groceriesB > 0, 'Seeded categories should exist.');

    $pdo->prepare(
        'INSERT INTO transactions (household_id, type, description, amount, category_id, transaction_date, notes, source)
         VALUES (?, \'expense\', ?, ?, ?, ?, ?, \'manual\')'
    )->execute([$householdA, 'Weekly shop', 42.50, $groceriesA, '2026-08-12', 'Imported later']);
    $pdo->prepare(
        'INSERT INTO recurring_entries
            (household_id, type, description, amount, category_id, frequency, interval_count, start_date, next_due_date, notes, active)
         VALUES (?, \'income\', ?, ?, ?, \'monthly\', 1, ?, ?, ?, 1)'
    )->execute([$householdA, 'Pay', 1000.00, $salaryA, '2026-01-01', '2026-10-01', 'Salary']);
    $pdo->prepare(
        'INSERT INTO category_budgets (household_id, category_id, amount) VALUES (?, ?, ?)'
    )->execute([$householdA, $groceriesA, 80.00]);

    $_SESSION['user_id'] = $ownerA;
    $txCsv = household_transactions_csv();
    portability_assert(str_contains($txCsv, 'Weekly shop'), 'Transactions CSV should include the row.');
    portability_assert(str_contains($txCsv, 'Groceries'), 'Transactions CSV should include the category name.');
    $recurringCsv = household_recurring_csv();
    portability_assert(str_contains($recurringCsv, 'Pay'), 'Recurring CSV should include the schedule.');

    $unknown = csv_preview_from_contents(
        "Date,Type,Description,Amount,Category,Notes\n2026-08-13,expense,Unknown,10.00,Not a category,\n",
        $householdA
    );
    portability_assert($unknown['errors'] !== [], 'Unknown categories should produce dry-run errors.');
    portability_assert($unknown['rows'] === [], 'Unknown categories should not produce importable rows.');

    $preview = csv_preview_from_contents($txCsv, $householdB);
    portability_assert($preview['errors'] === [], 'Exported CSV should dry-run cleanly into a household with the same category names.');
    portability_assert(count($preview['rows']) === 1, 'Preview should keep the exported transaction.');

    $_SESSION['user_id'] = $ownerB;
    $_SESSION['csv_import_preview'] = [
        'token' => 'test-token-' . $stamp,
        'kind' => 'transactions',
        'rows' => $preview['rows'],
        'household_id' => $householdB,
        'expires' => time() + 600,
    ];
    $imported = confirm_household_csv_import('test-token-' . $stamp);
    portability_assert($imported['inserted'] === 1, 'CSV confirm should insert the matching transaction into household B.');
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE household_id = ? AND description = ?');
    $countStmt->execute([$householdB, 'Weekly shop']);
    portability_assert((int) $countStmt->fetchColumn() === 1, 'Household B should have the imported shop row.');
    $countStmt->execute([$householdA, 'Weekly shop']);
    portability_assert((int) $countStmt->fetchColumn() === 1, 'CSV import into B must not duplicate household A rows.');

    $_SESSION['csv_import_preview'] = [
        'token' => 'test-token-2-' . $stamp,
        'kind' => 'transactions',
        'rows' => $preview['rows'],
        'household_id' => $householdB,
        'expires' => time() + 600,
    ];
    $again = confirm_household_csv_import('test-token-2-' . $stamp);
    portability_assert($again['inserted'] === 0 && $again['skipped'] === 1, 'A second import of the same row should skip.');

    $_SESSION['user_id'] = $ownerA;
    $document = build_household_backup_document($householdA);
    $json = json_encode($document, JSON_UNESCAPED_UNICODE);
    portability_assert(is_string($json) && str_contains($json, 'Weekly shop'), 'Backup document should include transactions.');
    $blob = encrypt_household_backup_payload($json, 'backup-passphrase-ok');
    portability_assert(!str_contains($blob, 'Weekly shop'), 'Encrypted backup must not contain plaintext descriptions.');
    $plain = decrypt_household_backup_payload($blob, 'backup-passphrase-ok');
    portability_assert(str_contains($plain, 'Weekly shop'), 'The correct passphrase should decrypt the backup.');
    $badPass = portability_exception_message(static function () use ($blob): void {
        decrypt_household_backup_payload($blob, 'wrong-passphrase-xx');
    });
    portability_assert($badPass !== '', 'A wrong backup passphrase should fail.');

    $_SESSION['user_id'] = $memberA;
    $memberRestore = portability_exception_message(static function () use ($document): void {
        restore_household_backup_document($document, false);
    });
    portability_assert(
        str_contains($memberRestore, 'Only the household owner can restore a backup.'),
        'Members must not restore backups.'
    );

    $_SESSION['user_id'] = $ownerB;
    $restored = restore_household_backup_document($document, false);
    portability_assert($restored['recurring'] >= 1, 'Owner B should merge household A backup recurring entries.');
    $recStmt = $pdo->prepare('SELECT COUNT(*) FROM recurring_entries WHERE household_id = ? AND description = ?');
    $recStmt->execute([$householdB, 'Pay']);
    portability_assert((int) $recStmt->fetchColumn() === 1, 'Restore should copy the recurring schedule into household B.');
    $countStmt->execute([$householdA, 'Weekly shop']);
    portability_assert((int) $countStmt->fetchColumn() === 1, 'Restore into B must not change household A transactions.');
    $budgetB = $pdo->prepare('SELECT amount FROM category_budgets WHERE household_id = ? AND category_id = ?');
    $budgetB->execute([$householdB, $groceriesB]);
    portability_assert((float) $budgetB->fetchColumn() === 80.00, 'Restore should copy the grocery budget onto household B.');

    $replaceDoc = [
        'format' => 'homeledger-backup',
        'version' => 1,
        'categories' => [['name' => 'Groceries', 'type' => 'expense', 'colour' => '#8d83ff', 'sort_order' => 10, 'budget' => 20]],
        'recurring' => [],
        'transactions' => [[
            'date' => '2026-09-01',
            'type' => 'expense',
            'description' => 'Replace only',
            'amount' => 5.00,
            'category_name' => 'Groceries',
            'category_type' => 'expense',
            'notes' => '',
            'source' => 'manual',
            'recurring_key' => null,
        ]],
    ];
    restore_household_backup_document($replaceDoc, true);
    $countStmt->execute([$householdB, 'Weekly shop']);
    portability_assert((int) $countStmt->fetchColumn() === 0, 'Replace restore should remove previous household B transactions.');
    $countStmt->execute([$householdB, 'Replace only']);
    portability_assert((int) $countStmt->fetchColumn() === 1, 'Replace restore should insert the backup transaction.');
    $countStmt->execute([$householdA, 'Weekly shop']);
    portability_assert((int) $countStmt->fetchColumn() === 1, 'Replace restore on B must leave household A intact.');
} catch (Throwable $exception) {
    portability_assert(false, 'Portability tests failed: ' . $exception->getMessage());
} finally {
    $_SESSION = [];
    try {
        portability_cleanup($pdo, $createdUsers, $createdHouseholds);
    } catch (Throwable $exception) {
        portability_assert(false, 'Portability test cleanup failed: ' . $exception->getMessage());
    }
}

$afterHousehold1Tx = (int) $pdo->query('SELECT COUNT(*) FROM transactions WHERE household_id = 1')->fetchColumn();
portability_assert($afterHousehold1Tx === $household1Tx, 'Household 1 money rows must stay untouched.');

if ($failures > 0) {
    fwrite(STDERR, "Portability tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Portability tests passed.\n");
exit(0);
