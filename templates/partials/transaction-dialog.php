<?php $allCategories = categories(); ?>
<dialog class="app-dialog" id="transaction-dialog" aria-labelledby="transaction-dialog-title">
    <form method="post" class="entry-form" id="transaction-form">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_transaction">
        <input type="hidden" name="id" value="">
        <div class="dialog-heading"><div><span class="eyebrow">ONE-TIME ENTRY</span><h2 id="transaction-dialog-title">Add transaction</h2></div><button class="icon-button close-dialog" type="button" aria-label="Close">×</button></div>
        <div class="segmented-control" role="group" aria-label="Transaction type">
            <label><input type="radio" name="type" value="expense" checked><span>Expense</span></label>
            <label><input type="radio" name="type" value="income"><span>Income</span></label>
        </div>
        <div class="form-grid">
            <label class="full-field"><span>Description</span><input name="description" required maxlength="160" placeholder="e.g. Weekly groceries"></label>
            <label><span>Amount (<?= e((string) config('app.currency')) ?>)</span><input name="amount" type="number" inputmode="decimal" min="0.01" max="99999999999.99" step="0.01" required placeholder="0.00"></label>
            <label><span>Date</span><input name="transaction_date" type="date" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label class="full-field"><span>Category</span><select name="category_id" required><option value="">Choose category</option><?php foreach ($allCategories as $category): ?><option value="<?= (int) $category['id'] ?>" data-type="<?= e($category['type']) ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
            <label class="full-field"><span>Note <small>Optional</small></span><textarea name="notes" maxlength="500" rows="3" placeholder="Add any helpful detail"></textarea></label>
        </div>
        <div class="dialog-actions"><button class="secondary-button close-dialog" type="button">Cancel</button><button class="primary-button" type="submit">Save entry</button></div>
    </form>
</dialog>
