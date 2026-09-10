<?php

declare(strict_types=1);

/** @var list<array{id:int,name:string,colour:string,spent:float,budget:float,period_budget:float,over:bool,percent:float}> $budgetWarnings */
if ($budgetWarnings === []) {
    return;
}
?>
<div class="budget-warning" role="status">
    <strong>Over budget</strong>
    <ul>
        <?php foreach ($budgetWarnings as $warning): ?>
            <li>
                <?= e((string) $warning['name']) ?>:
                <?= e(money($warning['spent'])) ?> spent of
                <?= e(money($warning['period_budget'])) ?> budget
            </li>
        <?php endforeach; ?>
    </ul>
</div>
