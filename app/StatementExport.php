<?php

declare(strict_types=1);

final class StatementExport
{
    /** @param array<string, mixed> $statement */
    public static function excel(array $statement): string
    {
        $rows = [];
        $rows[] = self::excelRow(['HomeLedger statement']);
        $rows[] = self::excelRow([(string) $statement['household_name']]);
        $rows[] = self::excelRow(['Period', $statement['from_label'] . ' to ' . $statement['to_label']]);
        $rows[] = self::excelRow(['Entries', (string) $statement['entry_count']]);
        $rows[] = self::excelRow([]);
        $rows[] = self::excelRow(['Income', self::plainMoney($statement['income'])], ['String', 'Number']);
        $rows[] = self::excelRow(['Expenses', self::plainMoney($statement['expense'])], ['String', 'Number']);
        $rows[] = self::excelRow(['Net', self::plainMoney($statement['balance'])], ['String', 'Number']);
        $rows[] = self::excelRow([]);
        $rows[] = self::excelRow(['Income by category']);
        foreach ($statement['income_categories'] as $item) {
            $rows[] = self::excelRow([(string) $item['name'], self::plainMoney($item['total'])], ['String', 'Number']);
        }
        if ($statement['income_categories'] === []) {
            $rows[] = self::excelRow(['None']);
        }
        $rows[] = self::excelRow([]);
        $rows[] = self::excelRow(['Expenses by category']);
        foreach ($statement['expense_categories'] as $item) {
            $rows[] = self::excelRow([(string) $item['name'], self::plainMoney($item['total'])], ['String', 'Number']);
        }
        if ($statement['expense_categories'] === []) {
            $rows[] = self::excelRow(['None']);
        }
        $rows[] = self::excelRow([]);
        $rows[] = self::excelRow(['Date', 'Description', 'Category', 'Type', 'Amount', 'Source']);
        foreach ($statement['transactions'] as $transaction) {
            $rows[] = self::excelRow([
                (string) $transaction['transaction_date'],
                (string) $transaction['description'],
                (string) $transaction['category_name'],
                (string) $transaction['type'],
                self::plainMoney($transaction['amount']),
                (string) $transaction['source'],
            ], ['String', 'String', 'String', 'String', 'Number', 'String']);
        }
        if ($statement['truncated']) {
            $rows[] = self::excelRow(['Showing the latest ' . $statement['list_limit'] . ' of ' . $statement['entry_count'] . ' entries.']);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<?mso-application progid="Excel.Sheet"?>' . "\n"
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Worksheet ss:Name="Statement"><Table>'
            . implode('', $rows)
            . '</Table></Worksheet></Workbook>';
    }

    /** @param array<string, mixed> $statement */
    public static function pdf(array $statement): string
    {
        $lines = [
            'HomeLedger statement',
            (string) $statement['household_name'],
            'Period: ' . $statement['from_label'] . ' to ' . $statement['to_label'],
            'Entries: ' . $statement['entry_count'],
            '',
            'Income: ' . money($statement['income']),
            'Expenses: ' . money($statement['expense']),
            'Net: ' . money($statement['balance']),
            '',
            'Income by category',
        ];
        foreach ($statement['income_categories'] as $item) {
            $lines[] = '  ' . $item['name'] . '  ' . money($item['total']);
        }
        if ($statement['income_categories'] === []) {
            $lines[] = '  None';
        }
        $lines[] = '';
        $lines[] = 'Expenses by category';
        foreach ($statement['expense_categories'] as $item) {
            $lines[] = '  ' . $item['name'] . '  ' . money($item['total']);
        }
        if ($statement['expense_categories'] === []) {
            $lines[] = '  None';
        }
        $lines[] = '';
        $lines[] = 'Entries';
        foreach ($statement['transactions'] as $transaction) {
            $sign = $transaction['type'] === 'income' ? '+' : '-';
            $date = (new DateTimeImmutable((string) $transaction['transaction_date']))->format('j M Y');
            $lines[] = '  ' . $date . '  ' . $transaction['description'] . '  ' . $transaction['category_name']
                . '  ' . $sign . money($transaction['amount']);
        }
        if ($statement['transactions'] === []) {
            $lines[] = '  No entries in this period';
        }
        if ($statement['truncated']) {
            $lines[] = '';
            $lines[] = 'Showing the latest ' . $statement['list_limit'] . ' of ' . $statement['entry_count'] . ' entries.';
        }

        return self::buildPdf($lines);
    }

    /** @param list<string> $cells */
    /** @param list<string> $types */
    private static function excelRow(array $cells, array $types = []): string
    {
        $xml = '<Row>';
        foreach ($cells as $index => $value) {
            $type = $types[$index] ?? 'String';
            if ($type === 'Number') {
                $xml .= '<Cell><Data ss:Type="Number">' . self::xml((string) $value) . '</Data></Cell>';
            } else {
                $xml .= '<Cell><Data ss:Type="String">' . self::xml((string) $value) . '</Data></Cell>';
            }
        }

        return $xml . '</Row>';
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function plainMoney(float|string|int $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** @param list<string> $lines */
    private static function buildPdf(array $lines): string
    {
        $pageWidth = 612;
        $pageHeight = 792;
        $margin = 50;
        $lineHeight = 14;
        $usable = (int) floor(($pageHeight - (2 * $margin)) / $lineHeight);
        $pages = array_chunk($lines, max(1, $usable));
        $contentIds = [];
        $objects = [];
        $nextId = 3;
        $fontId = $nextId++;
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        foreach ($pages as $pageLines) {
            $y = $pageHeight - $margin;
            $commands = "BT\n/F1 11 Tf\n";
            foreach ($pageLines as $index => $line) {
                $size = $index === 0 ? 16 : 11;
                $commands .= "/F1 {$size} Tf\n" . sprintf('1 0 0 1 %.2F %.2F Tm (%s) Tj' . "\n", $margin, $y, self::pdfEscape($line));
                $y -= $lineHeight + ($index === 0 ? 6 : 0);
            }
            $commands .= "ET\n";
            $contentId = $nextId++;
            $contentIds[] = $contentId;
            $objects[$contentId] = '<< /Length ' . strlen($commands) . " >>\nstream\n" . $commands . "endstream";
        }

        $pageIds = [];
        foreach ($contentIds as $contentId) {
            $pageId = $nextId++;
            $pageIds[] = $pageId;
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>',
                $pageWidth,
                $pageHeight,
                $contentId,
                $fontId
            );
        }

        $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            if (!isset($offsets[$id])) {
                $pdf .= "0000000000 65535 f \n";
                continue;
            }
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer << /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";

        return $pdf;
    }

    private static function pdfEscape(string $value): string
    {
        $value = str_replace(['£', '−'], ['GBP ', '-'], $value);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
