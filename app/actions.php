<?php

declare(strict_types=1);

function handle_post_action(): never
{
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $publicActions = ['login', 'setup', 'register', 'logout', 'resend_confirmation'];

    if ($action === 'logout') {
        destroy_user_session();
        flash('success', 'You have signed out.');
        redirect('login');
    }

    if (!in_array($action, $publicActions, true) && !is_authenticated()) {
        flash('error', 'Please sign in to continue.');
        redirect('login');
    }

    if ($action === 'setup') {
        $action = 'register';
    }
    if ($action === 'login' && is_authenticated()) {
        redirect('dashboard');
    }
    if ($action === 'register' && is_authenticated()) {
        redirect('dashboard');
    }

    try {
        switch ($action) {
            case 'login':
                login_user();
                break;
            case 'setup':
            case 'register':
                register_household();
                break;
            case 'resend_confirmation':
                resend_confirmation_email();
                break;
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
            case 'export_statement_pdf':
                export_statement('pdf');
                break;
            case 'export_statement_excel':
                export_statement('excel');
                break;
            case 'send_invite':
                send_invite();
                break;
            case 'revoke_invite':
                revoke_invite();
                break;
            case 'resend_invite':
                resend_invite();
                break;
            case 'update_profile':
                update_profile();
                break;
            case 'update_household':
                update_household_settings();
                break;
            case 'change_password':
                change_password();
                break;
            case 'delete_account':
                delete_account();
                break;
            default:
                throw new InvalidArgumentException('Unknown action.');
        }
        throw new LogicException('The requested action did not complete.');
    } catch (InvalidArgumentException $exception) {
        flash('error', $exception->getMessage());
        redirect_after_action_error($action);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        flash('error', 'The change could not be saved. Please check your details and try again.');
        redirect_after_action_error($action);
    }
}

function register_invite_query(): array
{
    $invite = $_POST['invite'] ?? '';
    if (!is_string($invite) || trim($invite) === '') {
        return [];
    }

    return ['invite' => trim($invite)];
}

function redirect_after_action_error(string $action): never
{
    if ($action === 'login') {
        redirect('login', ['next' => safe_next_page(is_string($_POST['next'] ?? null) ? $_POST['next'] : null)]);
    }
    if ($action === 'register') {
        redirect('register', register_invite_query());
    }
    if ($action === 'resend_confirmation') {
        $from = is_string($_POST['from'] ?? null) ? $_POST['from'] : '';
        redirect($from === 'check-email' ? 'check-email' : 'login');
    }
    if (str_contains($action, 'invite')) {
        redirect('household');
    }
    if (
        $action === 'update_profile'
        || $action === 'update_household'
        || $action === 'change_password'
        || $action === 'delete_account'
    ) {
        redirect_after_profile(true, $action === 'delete_account');
    }
    if (str_contains($action, 'statement')) {
        redirect('statement');
    }
    redirect(str_contains($action, 'recurring') ? 'recurring' : 'transactions');
}

function login_user(): void
{
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    remember_form(['email' => normalize_login_email($email)]);
    authenticate_with_password($email, $password);
    redirect(safe_next_page(is_string($_POST['next'] ?? null) ? $_POST['next'] : null));
}

function register_household(): void
{
    $displayName = (string) ($_POST['display_name'] ?? '');
    $email = (string) ($_POST['email'] ?? '');
    $householdName = (string) ($_POST['household_name'] ?? '');
    $invite = is_string($_POST['invite'] ?? null) ? trim((string) $_POST['invite']) : '';
    remember_form([
        'display_name' => trim($displayName),
        'email' => normalize_login_email($email),
        'household_name' => trim($householdName),
    ]);
    $created = create_household_owner(
        $displayName,
        $email,
        (string) ($_POST['password'] ?? ''),
        (string) ($_POST['password_confirm'] ?? ''),
        $householdName,
        $invite
    );
    unset($_SESSION['old_form']);
    if ($invite !== '') {
        establish_user_session((int) $created['id']);
        $joined = current_user();
        $householdLabel = is_array($joined) ? (string) ($joined['household_name'] ?? 'this household') : 'this household';
        flash('success', 'You have joined ' . $householdLabel . '. HomeLedger will only show this household\'s data.');
        redirect('dashboard');
    }

    $confirmToken = is_string($created['confirm_token'] ?? null) ? $created['confirm_token'] : '';
    $confirmExpires = $created['confirm_expires'] ?? null;
    if ($confirmToken === '' || !$confirmExpires instanceof DateTimeImmutable) {
        throw new RuntimeException('The confirmation email could not be prepared.');
    }
    publish_email_confirm_link((string) $created['login'], $confirmToken, $confirmExpires);
    flash_after_email_confirm_send(false);
    redirect('check-email');
}

function resend_confirmation_email(): void
{
    $email = (string) ($_POST['email'] ?? '');
    if ($email === '') {
        $email = pending_email_confirm_login();
    }
    remember_form(['email' => normalize_login_email($email)]);
    resend_email_confirmation($email);
    flash_after_email_confirm_send(true);
    redirect('check-email');
}

function flash_after_invite(bool $resent): void
{
    $status = pull_invite_mail_status();
    if ($status === 'sent') {
        flash(
            'success',
            $resent
                ? 'Invite email sent. The previous link no longer works. Copy the new link below as a backup.'
                : 'Invite email sent. Copy the link below as a backup.'
        );

        return;
    }
    if ($status === 'failed') {
        $reason = pull_invite_mail_error();
        $reasonSuffix = $reason !== '' ? ' ' . $reason : '';
        flash(
            'error',
            $resent
                ? 'Invite resent but email failed to send.' . $reasonSuffix . ' Copy the new link below. The previous link no longer works.'
                : 'Invite created but email failed to send.' . $reasonSuffix . ' Copy the link below.'
        );

        return;
    }

    flash(
        'error',
        $resent
            ? 'Invite resent. Email is not configured. Copy the new link below. The previous link no longer works.'
            : 'Invite created. Email is not configured. Copy the link below.'
    );
}

function send_invite(): void
{
    $email = (string) ($_POST['email'] ?? '');
    remember_form(['invite_email' => normalize_login_email($email)]);
    create_household_invite($email);
    flash_after_invite(false);
    redirect('household');
}

function resend_invite(): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    resend_household_invite((int) $id);
    flash_after_invite(true);
    redirect('household');
}

function revoke_invite(): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    revoke_household_invite((int) $id);
    flash('success', 'That invite was cancelled.');
    redirect('household');
}

function profile_return_page(): string
{
    return safe_next_page(is_string($_POST['return_page'] ?? null) ? $_POST['return_page'] : null);
}

function redirect_after_profile(bool $reopen = false, bool $reopenDelete = false): never
{
    $query = [];
    if ($reopen && (string) ($_POST['from_profile'] ?? '') === '1') {
        $query['profile'] = '1';
    }
    if ($reopenDelete) {
        $query['delete'] = '1';
        unset($query['profile']);
    }
    redirect(profile_return_page(), $query);
}

function update_profile(): void
{
    $displayName = (string) ($_POST['display_name'] ?? '');
    remember_form(['display_name' => trim($displayName)]);
    update_current_display_name($displayName);
    unset($_SESSION['old_form']);
    flash('success', 'Your display name was updated.');
    redirect_after_profile();
}

function update_household_settings(): void
{
    $householdName = (string) ($_POST['household_name'] ?? '');
    remember_form(['household_name' => trim($householdName)]);
    update_current_household_name($householdName);
    unset($_SESSION['old_form']);
    flash('success', 'The household name was updated.');
    redirect_after_profile();
}

function change_password(): void
{
    change_current_user_password(
        (string) ($_POST['current_password'] ?? ''),
        (string) ($_POST['password'] ?? ''),
        (string) ($_POST['password_confirm'] ?? '')
    );
    flash('success', 'Your password was updated.');
    redirect_after_profile();
}

function delete_account(): void
{
    $transferRaw = $_POST['transfer_user_id'] ?? null;
    $transferUserId = null;
    if (is_string($transferRaw) && ctype_digit($transferRaw)) {
        $transferUserId = (int) $transferRaw;
    } elseif (is_int($transferRaw)) {
        $transferUserId = $transferRaw;
    }

    delete_current_user_account(
        (string) ($_POST['current_password'] ?? ''),
        (string) ($_POST['confirm_household_id'] ?? ''),
        $transferUserId
    );
    destroy_user_session();
    flash('success', 'Your HomeLedger account was deleted.');
    redirect('login');
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

    $householdId = current_household_id();
    validate_entry_fields($type, $description, $amount, $categoryId, $date, $notes);

    if ($id) {
        if (!household_owns_transaction($id)) {
            throw new InvalidArgumentException('Transaction not found.');
        }
        $stmt = db()->prepare(
            'UPDATE transactions
             SET type = ?, description = ?, amount = ?, category_id = ?, transaction_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND household_id = ?'
        );
        $stmt->execute([$type, $description, $amount, $categoryId, $date, $notes ?: null, $id, $householdId]);
        flash('success', 'Transaction updated.');
    } else {
        $stmt = db()->prepare(
            'INSERT INTO transactions (household_id, type, description, amount, category_id, transaction_date, notes, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'manual\')'
        );
        $stmt->execute([$householdId, $type, $description, $amount, $categoryId, $date, $notes ?: null]);
        flash('success', 'Transaction added.');
    }

    redirect('transactions');
}

function delete_transaction(): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id || !household_owns_transaction($id)) {
        throw new InvalidArgumentException('Transaction not found.');
    }

    $stmt = db()->prepare('DELETE FROM transactions WHERE id = ? AND household_id = ?');
    $stmt->execute([$id, current_household_id()]);
    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('Transaction not found.');
    }
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

    $householdId = current_household_id();
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
        if (!household_owns_recurring($id)) {
            throw new InvalidArgumentException('Recurring entry not found.');
        }
        $existing = db()->prepare('SELECT next_due_date FROM recurring_entries WHERE id = ? AND household_id = ?');
        $existing->execute([$id, $householdId]);
        $nextDue = $existing->fetchColumn();
        if (!$nextDue) {
            throw new InvalidArgumentException('Recurring entry not found.');
        }

        $nextDue = max((string) $nextDue, $startDate);
        $stmt = db()->prepare(
            'UPDATE recurring_entries
             SET type = ?, description = ?, amount = ?, category_id = ?, frequency = ?, interval_count = ?,
                 start_date = ?, next_due_date = ?, end_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND household_id = ?'
        );
        $stmt->execute([
            $type, $description, $amount, $categoryId, $frequency, $interval,
            $startDate, $nextDue, $endDate ?: null, $notes ?: null, $id, $householdId,
        ]);
        flash('success', 'Recurring entry updated. Future transactions will use the new details.');
    } else {
        $stmt = db()->prepare(
            'INSERT INTO recurring_entries
             (household_id, type, description, amount, category_id, frequency, interval_count, start_date, next_due_date, end_date, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $householdId, $type, $description, $amount, $categoryId, $frequency, $interval,
            $startDate, $startDate, $endDate ?: null, $notes ?: null,
        ]);
        flash('success', 'Recurring entry added.');
    }

    materialise_due_recurring_entries(null, $householdId);
    redirect('recurring');
}

function toggle_recurring_entry(): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id || !household_owns_recurring($id)) {
        throw new InvalidArgumentException('Recurring entry not found.');
    }

    $stmt = db()->prepare('UPDATE recurring_entries SET active = NOT active, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND household_id = ?');
    $stmt->execute([$id, current_household_id()]);
    flash('success', 'Recurring entry status changed.');
    redirect('recurring');
}

function delete_recurring_entry(): void
{
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id || !household_owns_recurring($id)) {
        throw new InvalidArgumentException('Recurring entry not found.');
    }

    $stmt = db()->prepare('DELETE FROM recurring_entries WHERE id = ? AND household_id = ?');
    $stmt->execute([$id, current_household_id()]);
    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('Recurring entry not found.');
    }
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

function export_statement(string $format): never
{
    [$from, $to] = statement_date_range($_POST['from'] ?? null, $_POST['to'] ?? null);
    $statement = load_statement($from, $to);
    $basename = 'homeledger-statement-' . $from . '-' . $to;

    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $basename . '.xls"');
        echo StatementExport::excel($statement);
        exit;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $basename . '.pdf"');
    echo StatementExport::pdf($statement);
    exit;
}
