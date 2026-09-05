<?php

declare(strict_types=1);

[$from, $to, $rangeNote, $isDefaultRange] = statement_date_range($_GET['from'] ?? null, $_GET['to'] ?? null);
$statement = load_statement($from, $to);
$income = $statement['income'];
$expense = $statement['expense'];
$balance = $statement['balance'];
$entryCount = $statement['entry_count'];
$incomeCategories = $statement['income_categories'];
$expenseCategories = $statement['expense_categories'];
$transactions = $statement['transactions'];
$truncated = $statement['truncated'];
$listLimit = $statement['list_limit'];
$fromLabel = $statement['from_label'];
$toLabel = $statement['to_label'];
?>

<section class="content-section">
    <div class="page-intro">
        <div>
            <p class="section-kicker">PERIOD STATEMENT</p>
            <h2>Statement</h2>
            <p>Totals income and expenses between two dates. This is not a statement of bank or account balances.</p>
        </div>
    </div>

    <form class="filter-bar range-bar" method="get">
        <input type="hidden" name="page" value="statement">
        <label>
            <span>From</span>
            <input id="statement-from" name="from" type="date" value="<?= e($from) ?>" required>
        </label>
        <label>
            <span>To</span>
            <input id="statement-to" name="to" type="date" value="<?= e($to) ?>" required>
        </label>
        <button class="secondary-button" type="submit">Apply range</button>
        <?php if (!$isDefaultRange): ?>
            <a class="clear-link" href="?page=statement">This month to today</a>
        <?php endif; ?>
    </form>

    <form class="export-bar" method="post">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="from" value="<?= e($from) ?>">
        <input type="hidden" name="to" value="<?= e($to) ?>">
        <button class="secondary-button" type="submit" name="action" value="export_statement_pdf">Download PDF</button>
        <button class="secondary-button" type="submit" name="action" value="export_statement_excel">Download Excel</button>
    </form>

    <?php if ($rangeNote): ?>
        <p class="range-note" role="status"><?= e($rangeNote) ?></p>
    <?php endif; ?>

    <p class="period-caption"><?= e($fromLabel) ?> to <?= e($toLabel) ?> · <?= $entryCount ?> <?= $entryCount === 1 ? 'entry' : 'entries' ?></p>

    <div class="summary-grid period-summary">
        <article class="summary-card">
            <span class="summary-icon income"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#arrow-down"></use></svg></span>
            <p>Income</p>
            <strong><?= money($income) ?></strong>
            <small>Money received in this period</small>
        </article>
        <article class="summary-card">
            <span class="summary-icon expense"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#arrow-up"></use></svg></span>
            <p>Expenses</p>
            <strong><?= money($expense) ?></strong>
            <small>Money spent in this period</small>
        </article>
        <article class="summary-card balance-card">
            <span class="summary-icon"><svg aria-hidden="true"><use href="assets/icons/sprite.svg#wallet"></use></svg></span>
            <p>Net</p>
            <strong class="<?= $balance < 0 ? 'negative' : '' ?>"><?= money($balance) ?></strong>
            <small>Income minus expenses</small>
        </article>
    </div>

    <div class="dashboard-grid lower-grid">
        <article class="panel">
            <div class="panel-heading"><div><span class="eyebrow">INCOME</span><h3>By category</h3></div></div>
            <?php if ($incomeCategories): ?>
                <div class="spending-list">
                    <?php foreach ($incomeCategories as $item):
                        $percent = $income > 0 ? ((float) $item['total'] / $income) * 100 : 0;
                    ?>
                        <div class="spending-item">
                            <div><span class="category-dot" style="--category-colour: <?= e($item['colour']) ?>"></span><strong><?= e($item['name']) ?></strong></div>
                            <span><?= money($item['total']) ?></span>
                            <i><b style="--width: <?= e((string) $percent) ?>%; --category-colour: <?= e($item['colour']) ?>"></b></i>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state compact"><span>No income</span><p>Nothing received in this period.</p></div>
            <?php endif; ?>
        </article>
        <article class="panel">
            <div class="panel-heading"><div><span class="eyebrow">EXPENSES</span><h3>By category</h3></div></div>
            <?php if ($expenseCategories): ?>
                <div class="spending-list">
                    <?php foreach ($expenseCategories as $item):
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
                <div class="empty-state compact"><span>No expenses</span><p>Nothing spent in this period.</p></div>
            <?php endif; ?>
        </article>
    </div>

    <article class="panel statement-entries">
        <div class="panel-heading">
            <div><span class="eyebrow">ENTRIES</span><h3>In this period</h3></div>
        </div>
        <?php if ($truncated): ?>
            <p class="range-note">Showing the latest <?= $listLimit ?> of <?= $entryCount ?> entries. Totals above still include the full period.</p>
        <?php endif; ?>
        <?php if ($transactions): ?>
            <div class="transaction-list">
                <?php foreach ($transactions as $transaction): ?>
                    <div class="transaction-row">
                        <span class="transaction-mark" style="--category-colour: <?= e($transaction['category_colour']) ?>"><?= e(strtoupper(substr($transaction['category_name'], 0, 1))) ?></span>
                        <div>
                            <strong><?= e($transaction['description']) ?></strong>
                            <small><?= e($transaction['category_name']) ?> · <?= e((new DateTimeImmutable($transaction['transaction_date']))->format('j M Y')) ?></small>
                        </div>
                        <span class="amount <?= e($transaction['type']) ?>"><?= $transaction['type'] === 'income' ? '+' : '−' ?><?= money($transaction['amount']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state compact"><span>No entries in this period</span><p>Choose another date range or add a transaction.</p></div>
        <?php endif; ?>
    </article>
</section>
