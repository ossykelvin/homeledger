<?php

declare(strict_types=1);

const UNUSUAL_SPEND_RATIO = 1.25;
const UNUSUAL_SPEND_MIN_AMOUNT = 50.0;

function unusual_spend_threshold_met(float $current, float $previous): bool
{
    return $current >= UNUSUAL_SPEND_MIN_AMOUNT
        && $previous >= UNUSUAL_SPEND_MIN_AMOUNT
        && $current >= $previous * UNUSUAL_SPEND_RATIO;
}

function monthly_budget_for_period(float $monthlyAmount, string $from, string $to): float
{
    if ($monthlyAmount <= 0 || $from > $to || !valid_date($from) || !valid_date($to)) {
        return 0.0;
    }

    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    $cursor = $start->modify('first day of this month');
    $total = 0.0;
    $guard = 0;
    while ($cursor <= $end) {
        $monthEnd = $cursor->modify('last day of this month');
        $overlapStart = $cursor > $start ? $cursor : $start;
        $overlapEnd = $monthEnd < $end ? $monthEnd : $end;
        if ($overlapStart <= $overlapEnd) {
            $daysInMonth = (int) $monthEnd->format('j');
            $overlapDays = (int) $overlapStart->diff($overlapEnd)->days + 1;
            $total += $monthlyAmount * ($overlapDays / $daysInMonth);
        }
        $cursor = $cursor->modify('first day of next month');
        if (++$guard > 240) {
            break;
        }
    }

    return round($total, 2);
}

/** @return array<int, float> */
function household_category_budgets(?int $householdId = null): array
{
    $householdId = $householdId ?? current_household_id();
    $stmt = db()->prepare(
        'SELECT category_id, amount FROM category_budgets WHERE household_id = ?'
    );
    $stmt->execute([$householdId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['category_id']] = (float) $row['amount'];
    }

    return $map;
}

function save_category_budget(int $categoryId, string $amountRaw): void
{
    assert_household_owner('Only the household owner can manage budgets.');

    $category = find_household_category($categoryId);
    if ($category === null) {
        throw new InvalidArgumentException('Category not found.');
    }
    if ((string) $category['type'] !== 'expense') {
        throw new InvalidArgumentException('Budgets can only be set on expense categories.');
    }

    $amountRaw = trim($amountRaw);
    $householdId = current_household_id();
    $name = (string) $category['name'];
    $existing = household_category_budgets($householdId)[$categoryId] ?? null;

    if ($amountRaw === '' || $amountRaw === '0' || $amountRaw === '0.00') {
        $stmt = db()->prepare(
            'DELETE FROM category_budgets WHERE household_id = ? AND category_id = ?'
        );
        $stmt->execute([$householdId, $categoryId]);
        if ($stmt->rowCount() === 0 && $existing === null) {
            return;
        }
        bump_household_state(db(), $householdId);
        log_household_activity(
            $householdId,
            current_actor_user_id(),
            'budget_set',
            'Cleared ' . $name . ' monthly budget'
        );

        return;
    }

    $amount = filter_var($amountRaw, FILTER_VALIDATE_FLOAT);
    if ($amount === false || $amount <= 0 || $amount > 99999999999.99) {
        throw new InvalidArgumentException('Enter a monthly budget greater than zero, or leave it blank to clear.');
    }

    $amount = round((float) $amount, 2);
    $stmt = db()->prepare(
        'INSERT INTO category_budgets (household_id, category_id, amount)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE amount = VALUES(amount)'
    );
    $stmt->execute([$householdId, $categoryId, $amount]);
    if ($existing !== null && abs($existing - $amount) < 0.001) {
        return;
    }
    bump_household_state(db(), $householdId);
    log_household_activity(
        $householdId,
        current_actor_user_id(),
        'budget_set',
        'Set ' . $name . ' monthly budget to ' . money($amount)
    );
}

/**
 * @return list<array{
 *   id:int,name:string,colour:string,spent:float,budget:float,period_budget:float,over:bool,percent:float
 * }>
 */
function household_budget_progress(string $from, string $to, ?int $householdId = null): array
{
    $householdId = $householdId ?? current_household_id();
    $stmt = db()->prepare(
        'SELECT c.id, c.name, c.colour, b.amount AS budget,
                COALESCE((
                    SELECT SUM(t.amount)
                    FROM transactions t
                    WHERE t.household_id = c.household_id
                      AND t.category_id = c.id
                      AND t.type = \'expense\'
                      AND t.transaction_date BETWEEN ? AND ?
                ), 0) AS spent
         FROM category_budgets b
         JOIN categories c ON c.id = b.category_id AND c.household_id = b.household_id
         WHERE b.household_id = ? AND c.type = \'expense\'
         ORDER BY c.sort_order, c.name'
    );
    $stmt->execute([$from, $to, $householdId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $budget = (float) $row['budget'];
        $periodBudget = monthly_budget_for_period($budget, $from, $to);
        $spent = (float) $row['spent'];
        $rows[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'colour' => (string) $row['colour'],
            'spent' => $spent,
            'budget' => $budget,
            'period_budget' => $periodBudget,
            'over' => $periodBudget > 0 && $spent >= $periodBudget,
            'percent' => $periodBudget > 0 ? min(999, ($spent / $periodBudget) * 100) : 0.0,
        ];
    }

    return $rows;
}

/** @return list<array{id:int,name:string,colour:string,spent:float,budget:float,period_budget:float,over:bool,percent:float}> */
function household_budget_warnings(string $from, string $to, ?int $householdId = null): array
{
    $warnings = [];
    foreach (household_budget_progress($from, $to, $householdId) as $row) {
        if ($row['over']) {
            $warnings[] = $row;
        }
    }

    return $warnings;
}

function claim_household_spend_alert(int $householdId, string $alertKey, ?PDO $pdo = null): bool
{
    if ($householdId < 1 || $alertKey === '' || text_length($alertKey) > 80) {
        return false;
    }

    $pdo ??= db();
    try {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO household_spend_alerts (household_id, alert_key) VALUES (?, ?)'
        );
        $stmt->execute([$householdId, $alertKey]);

        return $stmt->rowCount() === 1;
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            return false;
        }
        throw $exception;
    }
}

/**
 * @return list<array{key:string,title:string,preheader:string,intro:string,plain:string,extra:string}>
 */
function household_spend_alert_candidates(int $householdId, string $month): array
{
    [$monthStart, $monthEnd, $selectedMonth] = month_range($month);
    $previousStart = (new DateTimeImmutable($monthStart))->modify('first day of last month');
    $previousEnd = $previousStart->modify('last day of this month');
    $monthLabel = (new DateTimeImmutable($monthStart))->format('F Y');

    $currentTotals = household_expense_totals_by_category($householdId, $monthStart, $monthEnd);
    $previousTotals = household_expense_totals_by_category($householdId, $previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d'));
    $budgets = household_category_budgets($householdId);
    $candidates = [];

    $categoryIds = array_unique(array_merge(array_keys($currentTotals), array_keys($budgets)));
    foreach ($categoryIds as $categoryId) {
        $current = $currentTotals[$categoryId] ?? null;
        $spent = $current !== null ? (float) $current['spent'] : 0.0;
        $previous = (float) ($previousTotals[$categoryId]['spent'] ?? 0);
        $budget = (float) ($budgets[$categoryId] ?? 0);
        $name = $current !== null
            ? (string) $current['name']
            : category_name_for_household($householdId, $categoryId);
        if ($name === '') {
            continue;
        }

        $unusual = unusual_spend_threshold_met($spent, $previous);
        $overBudget = $budget > 0 && $spent >= $budget;
        if (!$unusual && !$overBudget) {
            continue;
        }

        $reasons = [];
        $plainReasons = [];
        if ($unusual) {
            $reasons[] = 'This is at least 125% of last month (' . money($previous) . ').';
            $plainReasons[] = 'This is at least 125% of last month (' . money($previous) . ').';
        }
        if ($overBudget) {
            $reasons[] = 'This has reached the ' . money($budget) . ' monthly budget.';
            $plainReasons[] = 'This has reached the ' . money($budget) . ' monthly budget.';
        }

        $candidates[] = spend_alert_payload(
            'cat:' . $categoryId . ':' . $selectedMonth,
            $name . ' is high in ' . $monthLabel,
            $name . ' spending is ' . money($spent) . ' in ' . $monthLabel . '.',
            $name . ' spending is <strong style="color:#f5f4ef;">' . e(money($spent)) . '</strong> in ' . e($monthLabel) . '.',
            $reasons,
            $plainReasons
        );
    }

    $currentMonthSpend = household_expense_total($householdId, $monthStart, $monthEnd);
    $previousMonthSpend = household_expense_total(
        $householdId,
        $previousStart->format('Y-m-d'),
        $previousEnd->format('Y-m-d')
    );
    if (unusual_spend_threshold_met($currentMonthSpend, $previousMonthSpend)) {
        $candidates[] = spend_alert_payload(
            'month:' . $selectedMonth,
            'Spending is high in ' . $monthLabel,
            'Household expenses are ' . money($currentMonthSpend) . ' in ' . $monthLabel . '.',
            'Household expenses are <strong style="color:#f5f4ef;">' . e(money($currentMonthSpend)) . '</strong> in ' . e($monthLabel) . '.',
            ['This is at least 125% of last month (' . money($previousMonthSpend) . ').'],
            ['This is at least 125% of last month (' . money($previousMonthSpend) . ').']
        );
    }

    return $candidates;
}

function dispatch_household_spend_alerts(?int $householdId = null): void
{
    try {
        if (!mail_is_configured()) {
            return;
        }
        $householdId = $householdId ?? current_household_id();
        $members = household_member_emails($householdId);
        if ($members === []) {
            return;
        }
        foreach (household_spend_alert_candidates($householdId, date('Y-m')) as $candidate) {
            if (!claim_household_spend_alert($householdId, $candidate['key'])) {
                continue;
            }
            $html = account_mail_html(
                $candidate['title'],
                'Spend alert',
                $candidate['preheader'],
                $candidate['intro'],
                $candidate['extra'],
                'Open HomeLedger',
                rtrim(app_public_url(), '/') . '/',
                'HomeLedger sends this once per household, month and category.'
            );
            foreach ($members as $email) {
                send_account_notice_mail(
                    $email,
                    $candidate['title'],
                    $candidate['plain'],
                    $html,
                    'spend alert'
                );
            }
        }
    } catch (Throwable $exception) {
        error_log('HomeLedger spend alerts failed: ' . $exception->getMessage());
    }
}

/**
 * @param list<string> $reasons
 * @param list<string> $plainReasons
 * @return array{key:string,title:string,preheader:string,intro:string,plain:string,extra:string}
 */
function spend_alert_payload(
    string $key,
    string $title,
    string $preheader,
    string $introHtml,
    array $reasons,
    array $plainReasons
): array {
    $extraItems = '';
    foreach ($reasons as $reason) {
        $extraItems .= '<tr><td style="padding:4px 32px 0;font-family:Arial,Helvetica,sans-serif;color:#9ba3ad;font-size:14px;line-height:1.5;">'
            . e($reason)
            . '</td></tr>';
    }
    $plain = $preheader . "\n\n" . implode("\n", $plainReasons) . "\n\n"
        . "HomeLedger sends this once per household, month and category.\n\n"
        . "HomeLedger - Home money, clearly.\n"
        . mail_footer_host() . "\n";

    return [
        'key' => $key,
        'title' => $title,
        'preheader' => $preheader,
        'intro' => $introHtml,
        'plain' => $plain,
        'extra' => $extraItems,
    ];
}

/** @return array<int, array{id:int,name:string,colour:string,spent:float}> */
function household_expense_totals_by_category(int $householdId, string $from, string $to): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.name, c.colour, SUM(t.amount) AS spent
         FROM transactions t
         JOIN categories c ON c.id = t.category_id AND c.household_id = t.household_id
         WHERE t.household_id = ? AND t.type = \'expense\' AND t.transaction_date BETWEEN ? AND ?
         GROUP BY c.id, c.name, c.colour'
    );
    $stmt->execute([$householdId, $from, $to]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['id']] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'colour' => (string) $row['colour'],
            'spent' => (float) $row['spent'],
        ];
    }

    return $map;
}

function household_expense_total(int $householdId, string $from, string $to): float
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM transactions
         WHERE household_id = ? AND type = \'expense\' AND transaction_date BETWEEN ? AND ?'
    );
    $stmt->execute([$householdId, $from, $to]);

    return (float) $stmt->fetchColumn();
}

function category_name_for_household(int $householdId, int $categoryId): string
{
    $stmt = db()->prepare(
        'SELECT name FROM categories WHERE id = ? AND household_id = ?'
    );
    $stmt->execute([$categoryId, $householdId]);
    $name = $stmt->fetchColumn();

    return is_string($name) ? $name : '';
}

/** @return list<string> */
function household_member_emails(int $householdId): array
{
    $stmt = db()->prepare(
        'SELECT login FROM users WHERE household_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$householdId]);
    $emails = [];
    foreach ($stmt->fetchAll() as $row) {
        $email = normalize_login_email((string) ($row['login'] ?? ''));
        if (valid_login_email($email)) {
            $emails[] = $email;
        }
    }

    return $emails;
}
