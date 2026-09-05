<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

try {
    $throughDate = $argv[1] ?? date('Y-m-d');
    if (!valid_date($throughDate)) {
        throw new InvalidArgumentException('Use a date in YYYY-MM-DD format.');
    }
    $created = materialise_due_recurring_entries($throughDate);
    fwrite(STDOUT, "Created {$created} due transaction(s) through {$throughDate} across all households.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
