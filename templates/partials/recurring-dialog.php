<?php $allCategories = categories(); ?>
<dialog class="app-dialog" id="recurring-dialog" aria-labelledby="recurring-dialog-title">
    <form method="post" class="entry-form" id="recurring-form">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_recurring">
        <input type="hidden" name="id" value="">
        <div class="dialog-heading"><div><span class="eyebrow">REPEATING ENTRY</span><h2 id="recurring-dialog-title">Add recurring entry</h2></div><button class="icon-button close-dialog" type="button" aria-label="Close">×</button></div>
        <div class="segmented-control" role="group" aria-label="Entry type">
            <label><input type="radio" name="type" value="expense" checked><span>Expense</span></label>
            <label><input type="radio" name="type" value="income"><span>Income</span></label>
        </div>
        <div class="form-grid">
            <label class="full-field"><span>Description</span><input name="description" required maxlength="160" placeholder="e.g. Mortgage payment"></label>
            <label><span>Amount (<?= e((string) config('app.currency')) ?>)</span><input name="amount" type="number" inputmode="decimal" min="0.01" max="99999999999.99" step="0.01" required placeholder="0.00"></label>
            <label><span>Category</span><select name="category_id" required><option value="">Choose category</option><?php foreach ($allCategories as $category): ?><option value="<?= (int) $category['id'] ?>" data-type="<?= e($category['type']) ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
            <label><span>Repeat</span><select name="frequency" required><option value="weekly">Weekly</option><option value="monthly" selected>Monthly</option><option value="yearly">Yearly</option><option value="daily">Daily</option></select></label>
            <label><span>Every</span><input name="interval_count" type="number" min="1" max="365" value="1" required></label>
            <label><span>Start date</span><input name="start_date" type="date" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label><span>End date <small>Optional</small></span><input name="end_date" type="date"></label>
            <label class="full-field"><span>Note <small>Optional</small></span><textarea name="notes" maxlength="500" rows="3" placeholder="Add any helpful detail"></textarea></label>
        </div>
        <div class="dialog-actions"><button class="secondary-button close-dialog" type="button">Cancel</button><button class="primary-button" type="submit">Save recurring entry</button></div>
    </form>
</dialog>
