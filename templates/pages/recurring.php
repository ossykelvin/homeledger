<?php

declare(strict_types=1);

$stmt = db()->prepare(
    'SELECT r.*, c.name AS category_name, c.colour AS category_colour
     FROM recurring_entries r JOIN categories c ON c.id = r.category_id AND c.household_id = r.household_id
     WHERE r.household_id = ?
     ORDER BY r.active DESC, r.next_due_date, r.id'
);
$stmt->execute([current_household_id()]);
$recurringEntries = $stmt->fetchAll();
$monthlyIncome = 0.0;
$monthlyExpense = 0.0;
foreach ($recurringEntries as $entry) {
    if (!(int) $entry['active']) continue;
    $monthlyAmount = match ($entry['frequency']) {
        'daily' => (float) $entry['amount'] * (30.4375 / (int) $entry['interval_count']),
        'weekly' => (float) $entry['amount'] * (4.348 / (int) $entry['interval_count']),
        'monthly' => (float) $entry['amount'] / (int) $entry['interval_count'],
        'yearly' => (float) $entry['amount'] / (12 * (int) $entry['interval_count']),
    };
    if ($entry['type'] === 'income') $monthlyIncome += $monthlyAmount;
    else $monthlyExpense += $monthlyAmount;
}
?>

<section class="content-section">
    <div class="page-intro">
        <div><p class="section-kicker">AUTOMATIC ENTRIES</p><h2>Set it once.</h2><p>Regular income and bills are added automatically when they become due.</p></div>
        <button class="primary-button" type="button" data-open-dialog="recurring-dialog"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#plus"></use></svg>Add recurring</button>
    </div>

    <div class="mini-summary recurring-summary">
        <span><small>Active schedules</small><strong><?= count(array_filter($recurringEntries, fn($entry) => (int) $entry['active'])) ?></strong></span>
        <span><small>Est. monthly income</small><strong class="income-text">+<?= money($monthlyIncome) ?></strong></span>
        <span><small>Est. monthly expense</small><strong class="expense-text">−<?= money($monthlyExpense) ?></strong></span>
    </div>

    <div class="recurring-grid">
        <?php foreach ($recurringEntries as $entry):
            $payload = e(json_encode([
                'id' => (int) $entry['id'], 'type' => $entry['type'], 'description' => $entry['description'],
                'amount' => $entry['amount'], 'category_id' => (int) $entry['category_id'],
                'frequency' => $entry['frequency'], 'interval_count' => (int) $entry['interval_count'],
                'start_date' => $entry['start_date'], 'end_date' => $entry['end_date'], 'notes' => $entry['notes'],
            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}');
        ?>
            <article class="recurring-card <?= !(int) $entry['active'] ? 'paused' : '' ?>">
                <div class="recurring-card-top">
                    <span class="transaction-mark" style="--category-colour: <?= e($entry['category_colour']) ?>"><?= e(strtoupper(substr($entry['category_name'], 0, 1))) ?></span>
                    <span class="status-pill <?= (int) $entry['active'] ? 'active' : 'paused' ?>"><i></i><?= (int) $entry['active'] ? 'Active' : 'Paused' ?></span>
                </div>
                <div class="recurring-card-body">
                    <p class="eyebrow"><?= e(strtoupper($entry['category_name'])) ?></p>
                    <h3><?= e($entry['description']) ?></h3>
                    <strong class="amount <?= e($entry['type']) ?>"><?= $entry['type'] === 'income' ? '+' : '−' ?><?= money($entry['amount']) ?></strong>
                </div>
                <dl>
                    <?php $unit = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$entry['frequency']]; ?>
                    <div><dt>Repeats</dt><dd>Every <?= (int) $entry['interval_count'] > 1 ? (int) $entry['interval_count'] . ' ' : '' ?><?= e($unit) ?><?= (int) $entry['interval_count'] > 1 ? 's' : '' ?></dd></div>
                    <div><dt>Next due</dt><dd><?= e((new DateTimeImmutable($entry['next_due_date']))->format('j M Y')) ?></dd></div>
                </dl>
                <div class="card-actions">
                    <button class="secondary-button edit-recurring" type="button" data-recurring="<?= $payload ?>"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#edit"></use></svg>Edit</button>
                    <form method="post"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_recurring"><input type="hidden" name="id" value="<?= (int) $entry['id'] ?>"><button class="secondary-button" type="submit"><?= (int) $entry['active'] ? 'Pause' : 'Resume' ?></button></form>
                    <form method="post" data-confirm="Delete this recurring entry? Existing transactions will remain."><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_recurring"><input type="hidden" name="id" value="<?= (int) $entry['id'] ?>"><button class="icon-button danger" type="submit" aria-label="Delete <?= e($entry['description']) ?>"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#trash"></use></svg></button></form>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$recurringEntries): ?>
            <div class="empty-state large full-width"><span>Make regular money simple</span><p>Add rent, salary, subscriptions or any entry that repeats.</p><button class="primary-button" type="button" data-open-dialog="recurring-dialog">Add recurring entry</button></div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../partials/recurring-dialog.php'; ?>
