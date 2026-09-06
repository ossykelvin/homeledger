<?php

declare(strict_types=1);

$canManageCategories = current_user_is_household_owner();
$allCategories = household_categories_with_usage();
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$editing = null;
if ($canManageCategories && $editId > 0) {
    foreach ($allCategories as $category) {
        if ((int) $category['id'] === $editId) {
            $editing = $category;
            break;
        }
    }
}

$namePrefill = old_form('category_name', $editing ? (string) $editing['name'] : '');
$typePrefill = old_form('category_type', $editing ? (string) $editing['type'] : 'expense');
if (!in_array($typePrefill, ['income', 'expense'], true)) {
    $typePrefill = 'expense';
}
$colourPrefill = old_form(
    'category_colour',
    $editing ? (string) $editing['colour'] : ($typePrefill === 'income' ? '#c7f36b' : '#8d83ff')
);
$editingUsed = $editing !== null && (
    (int) $editing['transaction_count'] > 0 || (int) $editing['recurring_count'] > 0
);

$incomeCategories = [];
$expenseCategories = [];
foreach ($allCategories as $category) {
    if ($category['type'] === 'income') {
        $incomeCategories[] = $category;
    } else {
        $expenseCategories[] = $category;
    }
}

?>
<section class="content-section household-hub">
    <div class="page-intro">
        <div>
            <p class="section-kicker">LEDGER LABELS</p>
            <h2>Income and expense categories.</h2>
            <p><?= e($canManageCategories
                ? 'Add household categories and rename the starters. Type can change only when nothing uses the category. Used categories cannot be deleted.'
                : 'These labels group income and expense entries for this household. Only the owner can add or edit them.') ?></p>
        </div>
    </div>

    <?php if ($canManageCategories): ?>
        <form method="post" class="invite-form category-form" autocomplete="off">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_category">
            <?php if ($editing): ?>
                <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
            <?php endif; ?>
            <label>
                <span>Name</span>
                <input name="name" type="text" value="<?= $namePrefill ?>" maxlength="80" required placeholder="e.g. Childcare">
            </label>
            <label>
                <span>Type</span>
                <?php if ($editingUsed): ?>
                    <input type="hidden" name="type" value="<?= e((string) $editing['type']) ?>">
                    <input type="text" value="<?= e($editing['type'] === 'income' ? 'Income' : 'Expense') ?>" readonly>
                <?php else: ?>
                    <select name="type" required>
                        <option value="expense" <?= $typePrefill === 'expense' ? 'selected' : '' ?>>Expense</option>
                        <option value="income" <?= $typePrefill === 'income' ? 'selected' : '' ?>>Income</option>
                    </select>
                <?php endif; ?>
            </label>
            <label class="category-colour-field">
                <span>Colour</span>
                <input name="colour" type="color" value="<?= e($colourPrefill) ?>" required>
            </label>
            <div class="category-form-actions">
                <button class="primary-button" type="submit"><?= $editing ? 'Save category' : 'Add category' ?></button>
                <?php if ($editing): ?>
                    <a class="clear-link" href="?page=categories">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($editingUsed): ?>
            <p class="range-note">This category is in use, so its type stays <?= e($editing['type'] === 'income' ? 'income' : 'expense') ?>.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="range-note">Only the household owner can add, rename or delete categories. You can still use them on transactions and recurring entries.</p>
    <?php endif; ?>

    <?php foreach ([['Income', $incomeCategories], ['Expense', $expenseCategories]] as [$heading, $rows]): ?>
        <div class="household-section">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow"><?= e(strtoupper((string) $heading)) ?></p>
                    <h3><?= e((string) $heading) ?> categories</h3>
                </div>
            </div>
            <div class="table-panel">
                <div class="data-table-wrap">
                    <table class="data-table compact-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Use</th>
                                <?php if ($canManageCategories): ?>
                                    <th class="align-right">Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $category):
                                $used = (int) $category['transaction_count'] > 0 || (int) $category['recurring_count'] > 0;
                                $isEditingRow = $editing !== null && (int) $editing['id'] === (int) $category['id'];
                            ?>
                                <tr<?= $isEditingRow ? ' class="category-editing-row"' : '' ?>>
                                    <td>
                                        <span class="category-pill">
                                            <i style="--category-colour: <?= e((string) $category['colour']) ?>"></i>
                                            <?= e((string) $category['name']) ?>
                                        </span>
                                    </td>
                                    <td><?= e(category_usage_label($category)) ?></td>
                                    <?php if ($canManageCategories): ?>
                                        <td class="align-right">
                                            <div class="invite-row-actions">
                                                <a class="secondary-button" href="?page=categories&edit=<?= (int) $category['id'] ?>">Edit</a>
                                                <?php if (!$used): ?>
                                                    <form method="post" data-confirm="Delete this unused category?">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="delete_category">
                                                        <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                                                        <button class="secondary-button" type="submit">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="<?= $canManageCategories ? 3 : 2 ?>">
                                        <div class="empty-state compact">
                                            <span>No <?= e(strtolower((string) $heading)) ?> categories</span>
                                            <p><?= e($canManageCategories
                                                ? 'Add one with the form above.'
                                                : 'The owner has not added any yet.') ?></p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</section>
