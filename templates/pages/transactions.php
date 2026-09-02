<?php

declare(strict_types=1);

[$monthStart, $monthEnd, $selectedMonth] = month_range($_GET['month'] ?? null);
$typeFilter = in_array($_GET['type'] ?? '', ['income', 'expense'], true) ? (string) $_GET['type'] : '';
$search = trim((string) ($_GET['search'] ?? ''));

$where = ['t.transaction_date BETWEEN ? AND ?'];
$params = [$monthStart, $monthEnd];
if ($typeFilter !== '') {
    $where[] = 't.type = ?';
    $params[] = $typeFilter;
}
if ($search !== '') {
    $where[] = '(t.description LIKE ? OR c.name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$stmt = db()->prepare(
    'SELECT t.*, c.name AS category_name, c.colour AS category_colour
     FROM transactions t JOIN categories c ON c.id = t.category_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY t.transaction_date DESC, t.id DESC LIMIT 250'
);
$stmt->execute($params);
$transactions = $stmt->fetchAll();
$filteredIncome = 0.0;
$filteredExpense = 0.0;
foreach ($transactions as $item) {
    if ($item['type'] === 'income') $filteredIncome += (float) $item['amount'];
    else $filteredExpense += (float) $item['amount'];
}
?>

<section class="content-section">
    <div class="page-intro">
        <div><p class="section-kicker">ALL ACTIVITY</p><h2>Money in. Money out.</h2><p>Review and manage one-time and generated recurring entries.</p></div>
        <button class="primary-button" type="button" data-open-dialog="transaction-dialog"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#plus"></use></svg>Add transaction</button>
    </div>

    <div class="mini-summary">
        <span><small>Filtered income</small><strong class="income-text">+<?= money($filteredIncome) ?></strong></span>
        <span><small>Filtered expense</small><strong class="expense-text">−<?= money($filteredExpense) ?></strong></span>
        <span><small>Net</small><strong><?= money($filteredIncome - $filteredExpense) ?></strong></span>
    </div>

    <form class="filter-bar" method="get">
        <input type="hidden" name="page" value="transactions">
        <label><span>Month</span><input type="month" name="month" value="<?= e($selectedMonth) ?>"></label>
        <label><span>Type</span><select name="type"><option value="">All entries</option><option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>Income</option><option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>Expense</option></select></label>
        <label class="search-field"><span>Search</span><input type="search" name="search" value="<?= e($search) ?>" placeholder="Description or category"></label>
        <button class="secondary-button" type="submit">Apply filters</button>
        <?php if ($typeFilter || $search || $selectedMonth !== date('Y-m')): ?><a class="clear-link" href="?page=transactions">Clear</a><?php endif; ?>
    </form>

    <div class="table-panel">
        <?php if ($transactions): ?>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead><tr><th scope="col">Entry</th><th scope="col">Category</th><th scope="col">Date</th><th scope="col">Source</th><th scope="col" class="align-right">Amount</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($transactions as $transaction):
                        $payload = e(json_encode([
                            'id' => (int) $transaction['id'], 'type' => $transaction['type'],
                            'description' => $transaction['description'], 'amount' => $transaction['amount'],
                            'category_id' => (int) $transaction['category_id'], 'transaction_date' => $transaction['transaction_date'],
                            'notes' => $transaction['notes'],
                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}');
                    ?>
                        <tr>
                            <td><div class="table-entry"><span class="transaction-mark" style="--category-colour: <?= e($transaction['category_colour']) ?>"><?= e(strtoupper(substr($transaction['category_name'], 0, 1))) ?></span><span><strong><?= e($transaction['description']) ?></strong><small><?= e($transaction['notes'] ?: 'No note') ?></small></span></div></td>
                            <td><span class="category-pill"><i style="--category-colour: <?= e($transaction['category_colour']) ?>"></i><?= e($transaction['category_name']) ?></span></td>
                            <td><?= e((new DateTimeImmutable($transaction['transaction_date']))->format('j M Y')) ?></td>
                            <td><span class="source-pill <?= e($transaction['source']) ?>"><?= e(ucfirst($transaction['source'])) ?></span></td>
                            <td class="align-right amount <?= e($transaction['type']) ?>"><?= $transaction['type'] === 'income' ? '+' : '−' ?><?= money($transaction['amount']) ?></td>
                            <td>
                                <div class="row-actions">
                                    <button class="icon-button edit-transaction" type="button" data-transaction="<?= $payload ?>" aria-label="Edit <?= e($transaction['description']) ?>"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#edit"></use></svg></button>
                                    <form method="post" data-confirm="Delete this transaction?">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_transaction"><input type="hidden" name="id" value="<?= (int) $transaction['id'] ?>">
                                        <button class="icon-button danger" type="submit" aria-label="Delete <?= e($transaction['description']) ?>"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#trash"></use></svg></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state large"><span>No matching transactions</span><p>Try another filter or add a new entry.</p><button class="primary-button" type="button" data-open-dialog="transaction-dialog">Add transaction</button></div>
        <?php endif; ?>
    </div>
</section>
