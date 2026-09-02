<?php

declare(strict_types=1);

function handle_post_action(): never
{
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    try {
        switch ($action) {
            case 'save_transaction':
                save_transaction();
                break;
            case 'delete_transaction':
                delete_transaction();
                break;
            case 'save_recurring':
                save_recurring_entry();
                break;
            case 'toggle_recurring':
                toggle_recurring_entry();
                break;
            case 'delete_recurring':
                delete_recurring_entry();
                break;
            default:
                throw new InvalidArgumentException('Unknown action.');
        }
        throw new LogicException('The requested action did not complete.');
    } catch (InvalidArgumentException $exception) {
        flash('error', $exception->getMessage());
        redirect($action === 'save_recurring' ? 'recurring' : 'transactions');
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        flash('error', 'The change could not be saved. Please check your details and try again.');
        redirect(str_contains($action, 'recurring') ? 'recurring' : 'transactions');
    }
}

function save_transaction(): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
    $type = (string) ($_POST['type'] ?? '');
    $description = trim((string) ($_POST['description'] ?? ''));
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
    $date = (string) ($_POST['transaction_date'] ?? '');
    $notes = trim((string) ($_POST['notes'] ?? ''));

    validate_entry_fields($type, $description, $amount, $categoryId, $date, $notes);

    if ($id) {
        $stmt = db()->prepare(
            'UPDATE transactions
             SET type = ?, description = ?, amount = ?, category_id = ?, transaction_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$type, $description, $amount, $categoryId, $date, $notes ?: null, $id]);
        flash('success', 'Transaction updated.');
    } else {
        $stmt = db()->prepare(
            'INSERT INTO transactions (type, description, amount, category_id, transaction_date, notes, source)
             VALUES (?, ?, ?, ?, ?, ?, \'manual\')'
        );
        $stmt->execute([$type, $description, $amount, $categoryId, $date, $notes ?: null]);
        flash('success', 'Transaction added.');
    }

    redirect('transactions');
}

function delete_transaction(): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        throw new InvalidArgumentException('Transaction not found.');
    }

    $stmt = db()->prepare('DELETE FROM transactions WHERE id = ?');
    $stmt->execute([$id]);
    flash('success', 'Transaction deleted.');
    redirect('transactions');
}

function save_recurring_entry(): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
    $type = (string) ($_POST['type'] ?? '');
    $description = trim((string) ($_POST['description'] ?? ''));
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
    $startDate = (string) ($_POST['start_date'] ?? '');
    $endDate = trim((string) ($_POST['end_date'] ?? ''));
    $frequency = (string) ($_POST['frequency'] ?? '');
    $interval = filter_input(INPUT_POST, 'interval_count', FILTER_VALIDATE_INT) ?: 1;
    $notes = trim((string) ($_POST['notes'] ?? ''));

    validate_entry_fields($type, $description, $amount, $categoryId, $startDate, $notes);
    if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        throw new InvalidArgumentException('Choose a valid repeat frequency.');
    }
    if ($interval < 1 || $interval > 365) {
        throw new InvalidArgumentException('Repeat interval must be between 1 and 365.');
    }
    if ($endDate !== '' && (!valid_date($endDate) || $endDate < $startDate)) {
        throw new InvalidArgumentException('End date must be on or after the start date.');
    }

    if ($id) {
        $existing = db()->prepare('SELECT next_due_date FROM recurring_entries WHERE id = ?');
        $existing->execute([$id]);
        $nextDue = $existing->fetchColumn();
        if (!$nextDue) {
            throw new InvalidArgumentException('Recurring entry not found.');
        }

        $nextDue = max((string) $nextDue, $startDate);
        $stmt = db()->prepare(
            'UPDATE recurring_entries
             SET type = ?, description = ?, amount = ?, category_id = ?, frequency = ?, interval_count = ?,
                 start_date = ?, next_due_date = ?, end_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([
            $type, $description, $amount, $categoryId, $frequency, $interval,
            $startDate, $nextDue, $endDate ?: null, $notes ?: null, $id,
        ]);
        flash('success', 'Recurring entry updated. Future transactions will use the new details.');
    } else {
        $stmt = db()->prepare(
            'INSERT INTO recurring_entries
             (type, description, amount, category_id, frequency, interval_count, start_date, next_due_date, end_date, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $type, $description, $amount, $categoryId, $frequency, $interval,
            $startDate, $startDate, $endDate ?: null, $notes ?: null,
        ]);
        flash('success', 'Recurring entry added.');
    }

    materialise_due_recurring_entries();
    redirect('recurring');
}

function toggle_recurring_entry(): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        throw new InvalidArgumentException('Recurring entry not found.');
    }

    $stmt = db()->prepare('UPDATE recurring_entries SET active = NOT active, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$id]);
    flash('success', 'Recurring entry status changed.');
    redirect('recurring');
}

function delete_recurring_entry(): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        throw new InvalidArgumentException('Recurring entry not found.');
    }

    $stmt = db()->prepare('DELETE FROM recurring_entries WHERE id = ?');
    $stmt->execute([$id]);
    flash('success', 'Recurring entry deleted. Existing transactions were kept.');
    redirect('recurring');
}

function validate_entry_fields(
    string $type,
    string $description,
    float|false|null $amount,
    int|false|null $categoryId,
    string $date,
    string $notes
): void {
    if (!in_array($type, ['income', 'expense'], true)) {
        throw new InvalidArgumentException('Choose income or expense.');
    }
    if ($description === '' || text_length($description) > 160) {
        throw new InvalidArgumentException('Enter a description of up to 160 characters.');
    }
    if ($amount === false || $amount === null || $amount <= 0 || $amount > 99999999999.99) {
        throw new InvalidArgumentException('Enter an amount greater than zero.');
    }
    if (!$categoryId || !category_belongs_to_type((int) $categoryId, $type)) {
        throw new InvalidArgumentException('Choose a category that matches the entry type.');
    }
    if (!valid_date($date)) {
        throw new InvalidArgumentException('Enter a valid date.');
    }
    if (text_length($notes) > 500) {
        throw new InvalidArgumentException('Notes cannot be longer than 500 characters.');
    }
}
