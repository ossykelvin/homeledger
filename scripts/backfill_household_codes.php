<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

function household_public_code_column(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        'SELECT IS_NULLABLE, COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'households\'
           AND COLUMN_NAME = \'public_code\''
    );
    $stmt->execute();
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function household_public_code_has_unique(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'households\'
           AND INDEX_NAME = \'households_public_code_unique\''
    );
    $stmt->execute();

    return (int) $stmt->fetchColumn() > 0;
}

try {
    $pdo = db();
    $column = household_public_code_column($pdo);
    if ($column === null) {
        throw new RuntimeException(
            'households.public_code is missing. Apply database/migrations/005_household_public_code.sql first.'
        );
    }

    $missing = $pdo->query(
        'SELECT id FROM households WHERE public_code IS NULL OR public_code = \'\''
    )->fetchAll();
    $filled = 0;
    foreach ($missing as $row) {
        assign_household_public_code($pdo, (int) $row['id']);
        $filled++;
    }

    $stillMissing = (int) $pdo->query(
        'SELECT COUNT(*) FROM households WHERE public_code IS NULL OR public_code = \'\''
    )->fetchColumn();
    if ($stillMissing > 0) {
        throw new RuntimeException($stillMissing . ' household(s) still have no public_code.');
    }

    $needsNotNull = strtoupper((string) $column['IS_NULLABLE']) === 'YES';
    $needsUnique = !household_public_code_has_unique($pdo);
    if ($needsNotNull) {
        $pdo->exec('ALTER TABLE households MODIFY public_code CHAR(19) NOT NULL');
    }
    if ($needsUnique) {
        $pdo->exec('ALTER TABLE households ADD UNIQUE KEY households_public_code_unique (public_code)');
    }

    $total = (int) $pdo->query('SELECT COUNT(*) FROM households')->fetchColumn();
    fwrite(STDOUT, "Household public codes ready. Filled {$filled} of {$total} household(s).\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
