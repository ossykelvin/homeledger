<?php
declare(strict_types=1);
$pendingEmail = pending_email_confirm_login();
?>
<section class="auth-card">
    <p class="section-kicker">CONFIRM EMAIL</p>
    <h1>Check your inbox</h1>
    <p>Confirm this email to activate HomeLedger. The link expires in 24 hours.</p>
    <?php if ($pendingEmail !== ''): ?>
        <form method="post" class="auth-form" autocomplete="off">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="resend_confirmation">
            <input type="hidden" name="from" value="check-email">
            <input type="hidden" name="email" value="<?= e($pendingEmail) ?>">
            <button class="secondary-button" type="submit">Resend confirmation</button>
        </form>
    <?php endif; ?>
    <p class="auth-switch">Already confirmed? <a href="?page=login">Sign in</a></p>
</section>
