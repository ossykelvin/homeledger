<?php

declare(strict_types=1);

$displayPrefill = old_form('display_name', (string) ($currentUser['display_name'] ?? ''));
$householdPrefill = old_form('household_name', (string) ($currentUser['household_name'] ?? ''));
$loginEmail = (string) ($currentUser['login'] ?? '');
$householdPublicCode = (string) ($currentUser['household_public_code'] ?? '');
$returnPage = safe_next_page(is_string($page ?? null) ? $page : null);
?>
<dialog class="app-dialog profile-dialog" id="profile-dialog" aria-labelledby="profile-dialog-title">
    <div class="dialog-heading">
        <div>
            <span class="eyebrow">YOUR ACCOUNT</span>
            <h2 id="profile-dialog-title">Profile settings</h2>
        </div>
        <button class="icon-button close-dialog" type="button" aria-label="Close profile settings">×</button>
    </div>
    <div class="settings-stack">
        <form method="post" class="settings-card settings-form" autocomplete="on">
            <div>
                <p class="eyebrow">PROFILE</p>
                <h3>Your name</h3>
                <p>This is shown in the sidebar. It is not your sign-in email.</p>
            </div>
            <label>
                <span>Display name</span>
                <input name="display_name" type="text" value="<?= $displayPrefill ?>" autocomplete="name" maxlength="80" required>
            </label>
            <label>
                <span>Email</span>
                <input type="email" value="<?= e($loginEmail) ?>" readonly>
            </label>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="return_page" value="<?= e($returnPage) ?>">
            <input type="hidden" name="from_profile" value="1">
            <button class="primary-button" type="submit">Save name</button>
        </form>

        <form method="post" class="settings-card settings-form" autocomplete="off">
            <div>
                <p class="eyebrow">HOUSEHOLD</p>
                <h3>Household details</h3>
                <p>Everyone in this household shares the same ledger. This household ID cannot be changed.</p>
            </div>
            <label>
                <span>Household name</span>
                <input name="household_name" type="text" value="<?= $householdPrefill ?>" maxlength="80" required>
            </label>
            <label>
                <span>Household ID</span>
                <input class="household-code" type="text" value="<?= e($householdPublicCode) ?>" readonly onclick="this.select()" spellcheck="false">
            </label>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_household">
            <input type="hidden" name="return_page" value="<?= e($returnPage) ?>">
            <input type="hidden" name="from_profile" value="1">
            <button class="primary-button" type="submit">Save household name</button>
        </form>

        <form method="post" class="settings-card settings-form" autocomplete="off">
            <div>
                <p class="eyebrow">SECURITY</p>
                <h3>Change password</h3>
                <p>Use at least 12 characters. You will stay signed in after the change.</p>
            </div>
            <label>
                <span>Current password</span>
                <input name="current_password" type="password" autocomplete="current-password" minlength="12" required>
            </label>
            <label>
                <span>New password</span>
                <input name="password" type="password" autocomplete="new-password" minlength="12" required>
            </label>
            <label>
                <span>Confirm new password</span>
                <input name="password_confirm" type="password" autocomplete="new-password" minlength="12" required>
            </label>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="return_page" value="<?= e($returnPage) ?>">
            <input type="hidden" name="from_profile" value="1">
            <button class="primary-button" type="submit">Update password</button>
        </form>
    </div>
</dialog>
