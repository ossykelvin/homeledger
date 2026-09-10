<?php

declare(strict_types=1);

$inviteLink = pull_invite_link();
$invites = household_invites_for_current();
$members = household_members_for_current();
$householdName = (string) ($currentUser['household_name'] ?? 'this household');
$householdPublicCode = (string) ($currentUser['household_public_code'] ?? '');
$householdPrefill = old_form('household_name', $householdName);
$currentUserId = (int) ($currentUser['id'] ?? 0);
$ownerId = household_owner_user_id();
$canManageInvites = $currentUserId > 0 && $currentUserId === $ownerId;
$activity = household_activity_for_current(50);
$csvPreview = csv_import_preview_for_page();
$csvErrors = csv_import_errors_for_page();
$csvPreviewCount = is_array($csvPreview) ? count($csvPreview['rows'] ?? []) : 0;
$csvPreviewKind = is_array($csvPreview) ? (string) ($csvPreview['kind'] ?? 'transactions') : 'transactions';
$csvPreviewLabel = $csvPreviewKind === 'recurring' ? 'recurring entries' : 'transactions';
?>
<section class="content-section household-hub">
    <div class="page-intro">
        <div>
            <p class="section-kicker">HOUSEHOLD</p>
            <h2><?= e($householdName) ?></h2>
            <p>This is your household hub. Update the name, copy the household ID, see who has access, and invite someone to share this ledger. Export, import and encrypted backup live here too. The owner can remove a member or restore a backup after confirming the household ID.</p>
        </div>
    </div>

    <div class="household-details">
        <form method="post" class="invite-form household-name-form" autocomplete="off">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_household">
            <input type="hidden" name="return_page" value="household">
            <label>
                <span>Household name</span>
                <input name="household_name" type="text" value="<?= $householdPrefill ?>" maxlength="80" required>
            </label>
            <button class="primary-button" type="submit">Save name</button>
        </form>
        <div class="invite-link-card household-id-card">
            <p class="eyebrow">HOUSEHOLD ID</p>
            <p>Read-only. Share this code if you need to identify the household. It is not the invite link.</p>
            <label>
                <span>Household ID</span>
                <input class="household-code" type="text" readonly value="<?= e($householdPublicCode) ?>" onclick="this.select()" spellcheck="false">
            </label>
        </div>
    </div>

    <div class="household-section">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">PEOPLE</p>
                <h3>Members</h3>
            </div>
        </div>
        <div class="table-panel">
            <div class="data-table-wrap">
                <table class="data-table compact-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Joined</th>
                            <th>Role</th>
                            <?php if ($canManageInvites): ?>
                                <th class="align-right">Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member):
                            $memberId = (int) $member['id'];
                            $isYou = $memberId === $currentUserId;
                            $isOwner = $memberId === $ownerId;
                            $joined = new DateTimeImmutable((string) $member['created_at']);
                            $role = $isOwner ? 'Owner' : 'Member';
                        ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) $member['display_name']) ?></strong>
                                    <?php if ($isYou): ?><small>You</small><?php endif; ?>
                                </td>
                                <td><?= e((string) $member['login']) ?></td>
                                <td><?= e($joined->format('j M Y')) ?></td>
                                <td>
                                    <span class="status-pill <?= $isOwner ? 'active' : '' ?>">
                                        <i></i><?= e($role) ?>
                                    </span>
                                </td>
                                <?php if ($canManageInvites): ?>
                                    <td class="align-right">
                                        <?php if (!$isYou && !$isOwner): ?>
                                            <button
                                                class="secondary-button"
                                                type="button"
                                                data-open-dialog="remove-member-dialog"
                                                data-remove-member="<?= e((string) json_encode([
                                                    'id' => $memberId,
                                                    'name' => (string) $member['display_name'],
                                                    'email' => (string) $member['login'],
                                                ], JSON_UNESCAPED_UNICODE)) ?>"
                                            >Remove</button>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$members): ?>
                            <tr>
                                <td colspan="<?= $canManageInvites ? 5 : 4 ?>">
                                    <div class="empty-state compact">
                                        <span>No members found</span>
                                        <p>This household has no users yet.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="range-note">Everyone here has the same access to this ledger except invites and member removal, which only the owner can do. Ownership is stored on the household and can be transferred if the owner deletes their account. Membership cannot be moved to another household from this page.</p>
    </div>

    <div class="household-section">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">ACCESS</p>
                <h3>Invites</h3>
            </div>
        </div>
        <p class="range-note"><?= e($canManageInvites
            ? 'Send a 24-hour link so another person can join this household. They sign up with the invited email and share this ledger.'
            : 'Only the household owner can send or resend invites. You can still see who has been invited.') ?></p>

        <?php if ($canManageInvites && $inviteLink): ?>
            <div class="invite-link-card" role="status">
                <p class="eyebrow">INVITE LINK</p>
                <p>Share this link with <strong><?= e($inviteLink['email']) ?></strong>. It expires in 24 hours. Copy it if the email does not arrive.</p>
                <label>
                    <span>Invite URL</span>
                    <input type="text" readonly value="<?= e($inviteLink['url']) ?>" onclick="this.select()">
                </label>
            </div>
        <?php endif; ?>

        <?php if ($canManageInvites): ?>
        <form method="post" class="invite-form" autocomplete="off">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="send_invite">
            <label>
                <span>Email to invite</span>
                <input name="email" type="email" value="<?= old_form('invite_email') ?>" maxlength="190" required placeholder="name@example.com">
            </label>
            <button class="primary-button" type="submit">Send invite</button>
        </form>
        <p class="range-note">HomeLedger emails the link when SMTP is configured. Always copy it from this page as well. Resend issues a new 24-hour link and retires the old one. Up to 10 new invites per household per day.</p>
        <?php endif; ?>

        <div class="table-panel invite-table-panel">
            <div class="data-table-wrap">
                <table class="data-table compact-table">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th class="align-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invites as $invite):
                            $status = invite_status_label($invite);
                            $pending = $status === 'Pending' || $status === 'Expired';
                            $expires = new DateTimeImmutable((string) $invite['expires_at']);
                        ?>
                            <tr>
                                <td><?= e((string) $invite['email']) ?></td>
                                <td><?= e($expires->format('j M Y H:i')) ?></td>
                                <td>
                                    <span class="status-pill <?= $status === 'Pending' ? 'active' : ($status === 'Accepted' ? 'accepted' : 'paused') ?>">
                                        <i></i><?= e($status) ?>
                                    </span>
                                </td>
                                <td class="align-right">
                                    <?php if ($canManageInvites && $pending && empty($invite['accepted_at'])): ?>
                                        <div class="invite-row-actions">
                                            <form method="post" data-confirm="Send a new 24-hour link? The old link will stop working.">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="resend_invite">
                                                <input type="hidden" name="id" value="<?= (int) $invite['id'] ?>">
                                                <button class="secondary-button" type="submit">Resend</button>
                                            </form>
                                            <form method="post" data-confirm="Cancel this invite? The link will stop working.">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="revoke_invite">
                                                <input type="hidden" name="id" value="<?= (int) $invite['id'] ?>">
                                                <button class="secondary-button" type="submit">Cancel</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$invites): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state compact">
                                        <span>No invites yet</span>
                                        <p><?= e($canManageInvites
                                            ? 'Enter an email above to send a 24-hour join link for this household.'
                                            : 'No invites have been sent yet.') ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="household-section">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">HISTORY</p>
                <h3>Activity</h3>
            </div>
        </div>
        <p class="range-note">Recent household changes such as invites, joins, category edits, budgets and imports.</p>
        <div class="table-panel">
            <div class="data-table-wrap">
                <table class="data-table compact-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Event</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activity as $row):
                            $when = new DateTimeImmutable((string) $row['created_at']);
                        ?>
                            <tr>
                                <td><?= e($when->format('j M Y H:i')) ?></td>
                                <td><?= e(activity_event_label((string) $row['event_key'])) ?></td>
                                <td><?= e((string) $row['summary']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$activity): ?>
                            <tr>
                                <td colspan="3">
                                    <div class="empty-state compact">
                                        <span>No activity yet</span>
                                        <p>Invites, joins, category changes, budgets and imports will show up here.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="household-section">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">DATA</p>
                <h3>Export, import and backup</h3>
            </div>
        </div>
        <p class="range-note">Download this household as CSV, check a CSV before it writes anything, or keep an encrypted backup. Category names in a CSV must already exist. Restore is owner-only.</p>

        <?php if ($csvErrors): ?>
            <div class="invite-link-card import-error-card" role="status">
                <p class="eyebrow">CSV CHECK</p>
                <p>Nothing was imported. Fix these rows and check the file again.</p>
                <ul class="import-error-list">
                    <?php foreach ($csvErrors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (is_array($csvPreview)): ?>
            <div class="invite-link-card" role="status">
                <p class="eyebrow">CSV READY</p>
                <p>This file matches your categories. Confirm to import <strong><?= (int) $csvPreviewCount ?></strong> <?= e($csvPreviewLabel) ?>. Rows that already exist will be skipped.</p>
                <div class="export-bar">
                    <form method="post">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="confirm_csv_import">
                        <input type="hidden" name="import_token" value="<?= e((string) ($csvPreview['token'] ?? '')) ?>">
                        <button class="primary-button" type="submit">Import <?= (int) $csvPreviewCount ?> <?= e($csvPreviewLabel) ?></button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="cancel_csv_import">
                        <button class="secondary-button" type="submit">Cancel</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="household-details data-portability">
            <div class="settings-card">
                <h3>CSV export</h3>
                <p>Transactions and recurring schedules as UTF-8 CSV. Open in a spreadsheet or keep a copy.</p>
                <div class="export-bar">
                    <form method="post">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <button class="secondary-button" type="submit" name="action" value="export_csv_transactions">Download transactions CSV</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <button class="secondary-button" type="submit" name="action" value="export_csv_recurring">Download recurring CSV</button>
                    </form>
                </div>
            </div>
            <div class="settings-card">
                <h3>CSV import</h3>
                <p>Use the exported headers. Categories must match by name and type. Checking the file never writes rows.</p>
                <form method="post" class="settings-form" enctype="multipart/form-data">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="preview_csv_import">
                    <label>
                        <span>CSV file</span>
                        <input name="csv_file" type="file" accept=".csv,text/csv" required>
                    </label>
                    <button class="primary-button" type="submit">Check CSV</button>
                </form>
            </div>
        </div>

        <div class="household-details data-portability">
            <div class="settings-card">
                <h3>Encrypted backup</h3>
                <p>Download a locked copy of categories, budgets, recurring entries and transactions. Choose a passphrase you will remember. It is not your sign-in password unless you reuse it.</p>
                <form method="post" class="settings-form" autocomplete="off">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="download_household_backup">
                    <label>
                        <span>Backup passphrase</span>
                        <input name="backup_passphrase" type="password" autocomplete="new-password" minlength="12" required>
                    </label>
                    <label>
                        <span>Confirm passphrase</span>
                        <input name="backup_passphrase_confirm" type="password" autocomplete="new-password" minlength="12" required>
                    </label>
                    <button class="primary-button" type="submit">Download encrypted backup</button>
                </form>
            </div>
            <div class="settings-card <?= $canManageInvites ? 'danger-card' : '' ?>">
                <h3>Restore backup</h3>
                <?php if ($canManageInvites): ?>
                    <p>Owner only. Merges into this household, or replace transactions and recurring if you tick that box. Type the household ID and your current password.</p>
                    <button class="danger-button" type="button" data-open-dialog="restore-backup-dialog">Restore encrypted backup</button>
                <?php else: ?>
                    <p>Only the household owner can restore a backup into this ledger.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php if ($canManageInvites): ?>
<dialog
    class="app-dialog profile-dialog delete-account-dialog"
    id="remove-member-dialog"
    aria-labelledby="remove-member-dialog-title"
    data-household-code="<?= e($householdPublicCode) ?>"
>
    <form method="post" class="settings-stack" autocomplete="off">
        <div class="dialog-heading">
            <div>
                <span class="eyebrow">REMOVE MEMBER</span>
                <h2 id="remove-member-dialog-title">Remove a member</h2>
            </div>
            <button class="icon-button close-dialog" type="button" aria-label="Close remove member">×</button>
        </div>
        <div class="settings-card settings-form danger-card">
            <p>This removes their sign-in from this household. They will no longer see this ledger. Type the household ID and your current password to confirm.</p>
            <p class="household-id-reveal">Removing: <strong id="remove-member-label">this member</strong></p>
            <p class="household-id-reveal">Household ID: <strong class="household-code"><?= e($householdPublicCode) ?></strong></p>
            <label>
                <span>Type the household ID to confirm</span>
                <input
                    class="household-code"
                    name="confirm_household_id"
                    type="text"
                    inputmode="text"
                    autocomplete="off"
                    spellcheck="false"
                    autocapitalize="characters"
                    maxlength="24"
                    required
                    placeholder="XXXX-XXXX-XXXX-XXXX"
                >
            </label>
            <label>
                <span>Current password</span>
                <input name="current_password" type="password" autocomplete="current-password" minlength="12" required>
            </label>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="remove_member">
            <input type="hidden" name="user_id" value="">
            <div class="dialog-actions">
                <button class="secondary-button close-dialog" type="button">Cancel</button>
                <button class="danger-button" type="submit" disabled>Remove member</button>
            </div>
        </div>
    </form>
</dialog>
<dialog
    class="app-dialog profile-dialog delete-account-dialog"
    id="restore-backup-dialog"
    aria-labelledby="restore-backup-dialog-title"
    data-household-code="<?= e($householdPublicCode) ?>"
>
    <form method="post" class="settings-stack" autocomplete="off" enctype="multipart/form-data">
        <div class="dialog-heading">
            <div>
                <span class="eyebrow">RESTORE BACKUP</span>
                <h2 id="restore-backup-dialog-title">Restore a backup</h2>
            </div>
            <button class="icon-button close-dialog" type="button" aria-label="Close restore backup">×</button>
        </div>
        <div class="settings-card settings-form danger-card">
            <p>This writes categories, budgets, recurring entries and transactions into this household. Type the household ID and your current password. Use the passphrase chosen when the backup was downloaded.</p>
            <p class="household-id-reveal">Household ID: <strong class="household-code"><?= e($householdPublicCode) ?></strong></p>
            <label>
                <span>Backup file</span>
                <input name="backup_file" type="file" accept=".hlb,application/octet-stream" required>
            </label>
            <label>
                <span>Backup passphrase</span>
                <input name="backup_passphrase" type="password" autocomplete="off" minlength="12" required>
            </label>
            <label>
                <span>Type the household ID to confirm</span>
                <input
                    class="household-code"
                    name="confirm_household_id"
                    type="text"
                    inputmode="text"
                    autocomplete="off"
                    spellcheck="false"
                    autocapitalize="characters"
                    maxlength="24"
                    required
                    placeholder="XXXX-XXXX-XXXX-XXXX"
                >
            </label>
            <label>
                <span>Current password</span>
                <input name="current_password" type="password" autocomplete="current-password" minlength="12" required>
            </label>
            <label class="checkbox-field">
                <input name="replace_ledger" type="checkbox" value="1">
                <span>Replace this household's transactions and recurring entries instead of merging</span>
            </label>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="restore_household_backup">
            <div class="dialog-actions">
                <button class="secondary-button close-dialog" type="button">Cancel</button>
                <button class="danger-button" type="submit" disabled>Restore backup</button>
            </div>
        </div>
    </form>
</dialog>
<?php endif; ?>
