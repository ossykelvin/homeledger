<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$statement = [
    'from' => '2026-08-01',
    'to' => '2026-08-31',
    'from_label' => '1 Aug 2026',
    'to_label' => '31 Aug 2026',
    'income' => 2744.47,
    'expense' => 0.0,
    'balance' => 2744.47,
    'entry_count' => 1,
    'list_limit' => 250,
    'truncated' => false,
    'household_name' => 'Test household',
    'income_categories' => [['name' => 'Salary', 'total' => 2744.47]],
    'expense_categories' => [],
    'transactions' => [[
        'transaction_date' => '2026-08-25',
        'description' => 'FM SYSTEMS',
        'category_name' => 'Salary',
        'type' => 'income',
        'amount' => 2744.47,
        'source' => 'manual',
    ]],
];

$excel = StatementExport::excel($statement);
if (!str_contains($excel, 'FM SYSTEMS') || !str_contains($excel, 'Workbook')) {
    fwrite(STDERR, "Excel export missing expected content\n");
    exit(1);
}

$pdf = StatementExport::pdf($statement);
if (!str_starts_with($pdf, '%PDF-1.4') || !str_contains($pdf, '%%EOF') || !str_contains($pdf, 'FM SYSTEMS')) {
    fwrite(STDERR, "PDF export missing expected content\n");
    exit(1);
}

fwrite(STDOUT, "Statement export tests passed\n");
