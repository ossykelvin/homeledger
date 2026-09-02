<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Recurrence.php';

$cases = [
    ['2026-09-02', 'daily', 1, '2026-09-03'],
    ['2026-09-02', 'weekly', 2, '2026-09-16'],
    ['2026-01-31', 'monthly', 1, '2026-02-28'],
    ['2024-01-31', 'monthly', 1, '2024-02-29'],
    ['2024-02-29', 'yearly', 1, '2025-02-28'],
    ['2026-11-30', 'monthly', 3, '2027-02-28'],
];

$failures = 0;
foreach ($cases as [$date, $frequency, $interval, $expected]) {
    $actual = Recurrence::nextDate($date, $frequency, $interval);
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, "FAIL {$date} {$frequency} x{$interval}: expected {$expected}, got {$actual}\n");
    }
}

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, 'Recurrence tests passed: ' . count($cases) . "\n");
