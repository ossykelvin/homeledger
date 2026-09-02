<?php

declare(strict_types=1);

[$monthStart, $monthEnd, $selectedMonth] = month_range($_GET['month'] ?? null);

$summaryStmt = db()->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN type = \'income\' THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN type = \'expense\' THEN amount ELSE 0 END), 0) AS expense,
        COUNT(*) AS entry_count
     FROM transactions WHERE transaction_date BETWEEN ? AND ?'
);
$summaryStmt->execute([$monthStart, $monthEnd]);
$summary = $summaryStmt->fetch();
$income = (float) $summary['income'];
$expense = (float) $summary['expense'];
$balance = $income - $expense;
$savingRate = $income > 0 ? (($income - $expense) / $income) * 100 : 0;

$recentStmt = db()->prepare(
    'SELECT t.*, c.name AS category_name, c.colour AS category_colour
     FROM transactions t JOIN categories c ON c.id = t.category_id
     WHERE t.transaction_date BETWEEN ? AND ?
     ORDER BY t.transaction_date DESC, t.id DESC LIMIT 6'
);
$recentStmt->execute([$monthStart, $monthEnd]);
$recent = $recentStmt->fetchAll();

$categoryStmt = db()->prepare(
    'SELECT c.name, c.colour, SUM(t.amount) AS total
     FROM transactions t JOIN categories c ON c.id = t.category_id
     WHERE t.type = \'expense\' AND t.transaction_date BETWEEN ? AND ?
     GROUP BY c.id, c.name, c.colour ORDER BY total DESC LIMIT 5'
);
$categoryStmt->execute([$monthStart, $monthEnd]);
$spendingCategories = $categoryStmt->fetchAll();

$chartStart = (new DateTimeImmutable($monthStart))->modify('-5 months')->format('Y-m-01');
$chartStmt = db()->prepare(
    'SELECT DATE_FORMAT(transaction_date, \'%Y-%m\') AS month_key,
        SUM(CASE WHEN type = \'income\' THEN amount ELSE 0 END) AS income,
        SUM(CASE WHEN type = \'expense\' THEN amount ELSE 0 END) AS expense
     FROM transactions WHERE transaction_date BETWEEN ? AND ?
     GROUP BY month_key ORDER BY month_key'
);
$chartStmt->execute([$chartStart, $monthEnd]);
$chartRows = [];
foreach ($chartStmt->fetchAll() as $row) {
    $chartRows[$row['month_key']] = $row;
}
$chartData = [];
$maxChartValue = 1.0;
for ($i = 5; $i >= 0; $i--) {
    $date = (new DateTimeImmutable($monthStart))->modify("-{$i} months");
    $key = $date->format('Y-m');
    $item = [
        'label' => $date->format('M'),
        'income' => (float) ($chartRows[$key]['income'] ?? 0),
        'expense' => (float) ($chartRows[$key]['expense'] ?? 0),
    ];
    $maxChartValue = max($maxChartValue, $item['income'], $item['expense']);
    $chartData[] = $item;
}

$upcoming = db()->query(
    'SELECT r.*, c.name AS category_name, c.colour AS category_colour
     FROM recurring_entries r JOIN categories c ON c.id = r.category_id
     WHERE r.active = 1 AND (r.end_date IS NULL OR r.next_due_date <= r.end_date)
     ORDER BY r.next_due_date, r.id LIMIT 4'
)->fetchAll();
?>

<section class="dashboard-section">
    <div class="dashboard-heading">
        <div>
            <p class="section-kicker">YOUR MONTH AT A GLANCE</p>
            <h2><?= e((new DateTimeImmutable($monthStart))->format('F Y')) ?></h2>
        </div>
        <form class="month-picker" method="get">
            <input type="hidden" name="page" value="dashboard">
            <label for="dashboard-month">View month</label>
            <input id="dashboard-month" name="month" type="month" value="<?= e($selectedMonth) ?>" max="<?= e(date('Y-m')) ?>" onchange="this.form.submit()">
        </form>
    </div>

    <div class="summary-grid">
        <article class="summary-card balance-card">
            <span class="summary-icon"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#wallet"></use></svg></span>
            <p>Net balance</p>
            <strong class="<?= $balance < 0 ? 'negative' : '' ?>"><?= money($balance) ?></strong>
            <small><?= (int) $summary['entry_count'] ?> entries this month</small>
            <span class="corner-tag">01</span>
        </article>
        <article class="summary-card">
            <span class="summary-icon income"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#arrow-down"></use></svg></span>
            <p>Income</p>
            <strong><?= money($income) ?></strong>
            <small>Money received</small>
            <span class="corner-tag">02</span>
        </article>
        <article class="summary-card">
            <span class="summary-icon expense"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#arrow-up"></use></svg></span>
            <p>Expenses</p>
            <strong><?= money($expense) ?></strong>
            <small>Money spent</small>
            <span class="corner-tag">03</span>
        </article>
        <article class="summary-card rate-card">
            <div class="rate-ring" style="--rate: <?= e((string) max(0, min(100, $savingRate))) ?>"><span><?= number_format($savingRate, 0) ?>%</span></div>
            <div><p>Savings rate</p><strong><?= money($balance) ?></strong><small>Income kept</small></div>
            <span class="corner-tag">04</span>
        </article>
    </div>

    <div class="dashboard-grid">
        <article class="panel cashflow-panel">
            <div class="panel-heading">
                <div><span class="eyebrow">SIX MONTHS</span><h3>Cash flow</h3></div>
                <div class="legend"><span><i class="income-key"></i>Income</span><span><i class="expense-key"></i>Expense</span></div>
            </div>
            <div class="bar-chart" role="img" aria-label="Income and expenses for the last six months">
                <?php foreach ($chartData as $point): ?>
                    <div class="bar-group">
                        <div class="bars">
                            <i class="bar income-bar" style="--height: <?= e((string) (($point['income'] / $maxChartValue) * 100)) ?>%" title="Income <?= e(money($point['income'])) ?>"></i>
                            <i class="bar expense-bar" style="--height: <?= e((string) (($point['expense'] / $maxChartValue) * 100)) ?>%" title="Expense <?= e(money($point['expense'])) ?>"></i>
                        </div>
                        <span><?= e($point['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="panel spending-panel">
            <div class="panel-heading"><div><span class="eyebrow">BREAKDOWN</span><h3>Top spending</h3></div></div>
            <?php if ($spendingCategories): ?>
                <div class="spending-list">
                    <?php foreach ($spendingCategories as $item):
                        $percent = $expense > 0 ? ((float) $item['total'] / $expense) * 100 : 0;
                    ?>
                        <div class="spending-item">
                            <div><span class="category-dot" style="--category-colour: <?= e($item['colour']) ?>"></span><strong><?= e($item['name']) ?></strong></div>
                            <span><?= money($item['total']) ?></span>
                            <i><b style="--width: <?= e((string) $percent) ?>%; --category-colour: <?= e($item['colour']) ?>"></b></i>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state compact"><span>Nothing spent yet</span><p>Add an expense to see where your money goes.</p></div>
            <?php endif; ?>
        </article>
    </div>

    <div class="dashboard-grid lower-grid">
        <article class="panel">
            <div class="panel-heading">
                <div><span class="eyebrow">LATEST</span><h3>Recent transactions</h3></div>
                <a class="text-link" href="?page=transactions">View all <span>↗</span></a>
            </div>
            <?php if ($recent): ?>
                <div class="transaction-list">
                    <?php foreach ($recent as $transaction): ?>
                        <div class="transaction-row">
                            <span class="transaction-mark" style="--category-colour: <?= e($transaction['category_colour']) ?>"><?= e(strtoupper(substr($transaction['category_name'], 0, 1))) ?></span>
                            <div><strong><?= e($transaction['description']) ?></strong><small><?= e($transaction['category_name']) ?> · <?= e((new DateTimeImmutable($transaction['transaction_date']))->format('j M')) ?></small></div>
                            <span class="amount <?= e($transaction['type']) ?>"><?= $transaction['type'] === 'income' ? '+' : '−' ?><?= money($transaction['amount']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><span>Your month is ready</span><p>Add the first income or expense to begin.</p><button type="button" class="text-button" data-open-dialog="transaction-dialog">Add first entry</button></div>
            <?php endif; ?>
        </article>

        <article class="panel">
            <div class="panel-heading">
                <div><span class="eyebrow">COMING UP</span><h3>Recurring entries</h3></div>
                <a class="text-link" href="?page=recurring">Manage <span>↗</span></a>
            </div>
            <?php if ($upcoming): ?>
                <div class="transaction-list">
                    <?php foreach ($upcoming as $entry): ?>
                        <div class="transaction-row">
                            <span class="transaction-mark repeat-mark"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#repeat"></use></svg></span>
                            <div><strong><?= e($entry['description']) ?></strong><small><?= e(ucfirst($entry['frequency'])) ?> · next <?= e((new DateTimeImmutable($entry['next_due_date']))->format('j M')) ?></small></div>
                            <span class="amount <?= e($entry['type']) ?>"><?= $entry['type'] === 'income' ? '+' : '−' ?><?= money($entry['amount']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><span>No recurring entries</span><p>Set up regular bills or income and HomeLedger will post them for you.</p><a class="text-button" href="?page=recurring">Set one up</a></div>
            <?php endif; ?>
        </article>
    </div>
</section>
