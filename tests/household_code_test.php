<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/helpers.php';

$failures = 0;

function household_code_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $message . "\n");
}

for ($i = 0; $i < 40; $i++) {
    $code = generate_household_public_code();
    household_code_assert(strlen($code) === 19, 'Code length should be 19 including hyphens: ' . $code);
    household_code_assert(valid_household_public_code($code), 'Code should match 4-4-4-4 alphabet: ' . $code);
    household_code_assert(
        !preg_match('/[01IO]/', $code),
        'Code should omit ambiguous 0, 1, I and O: ' . $code
    );
}

household_code_assert(!valid_household_public_code('A3K9-M2PQ-7X2B-Q8N'), 'Short code should fail.');
household_code_assert(!valid_household_public_code('a3k9-m2pq-7x2b-q8nl'), 'Lowercase should fail.');
household_code_assert(!valid_household_public_code('A3K9M2PQ7X2BQ8NL'), 'Missing hyphens should fail.');
household_code_assert(valid_household_public_code('A3K9-M2PQ-7X2B-Q8NL'), 'Sample code should pass.');

if ($failures > 0) {
    fwrite(STDERR, "Household code tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Household code tests passed.\n");
exit(0);
