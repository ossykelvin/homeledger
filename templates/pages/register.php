<?php declare(strict_types=1);
$joining = !empty($openInvite);
$inviteEmail = $joining ? (string) ($openInvite['email'] ?? '') : '';
$householdLabel = $joining ? (string) ($openInvite['household_name'] ?? 'this household') : '';
$emailPrefill = $joining ? e($inviteEmail) : old_form('email');
?>
<section class="auth-card">
    <p class="section-kicker"><?= $joining ? 'JOIN HOUSEHOLD' : 'NEW HOUSEHOLD' ?></p>
    <h1><?= $joining ? 'Join this household' : 'Create your household' ?></h1>
    <?php if ($joining): ?>
        <p>You are joining <strong><?= e($householdLabel) ?></strong>. Use the invited email and a password of at least 12 characters. You will share this household's ledger.</p>
    <?php else: ?>
        <?php if (!empty($inviteProblem)): ?>
            <p class="auth-alert" role="alert"><?= e((string) $inviteProblem) ?></p>
        <?php endif; ?>
        <p>Each household has its own ledger. Use an email you control and a password of at least 12 characters. We will email a confirmation link to activate HomeLedger.</p>
    <?php endif; ?>
    <form method="post" class="auth-form" autocomplete="on">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="register">
        <?php if ($joining): ?>
            <input type="hidden" name="invite" value="<?= e($inviteToken) ?>">
        <?php endif; ?>
        <?php if (!$joining): ?>
            <label>
                <span>Household name</span>
                <input name="household_name" type="text" value="<?= old_form('household_name') ?>" maxlength="80" placeholder="e.g. The Njimogu household">
            </label>
        <?php endif; ?>
        <label>
            <span>Your name</span>
            <input name="display_name" type="text" value="<?= old_form('display_name') ?>" autocomplete="name" maxlength="80" required>
        </label>
        <label>
            <span>Email</span>
            <input name="email" type="email" value="<?= $emailPrefill ?>" autocomplete="username" maxlength="190" required<?= $joining ? ' readonly' : '' ?>>
        </label>
        <label>
            <span>Password</span>
            <input name="password" type="password" autocomplete="new-password" minlength="12" required>
        </label>
        <label>
            <span>Confirm password</span>
            <input name="password_confirm" type="password" autocomplete="new-password" minlength="12" required>
        </label>
        <button class="primary-button" type="submit"><?= $joining ? 'Join household' : 'Create household' ?></button>
    </form>
    <p class="auth-switch">Already have an account? <a href="?page=login">Sign in</a><?php if ($joining): ?> · <a href="?page=register">Create your own household</a><?php endif; ?></p>
</section>
