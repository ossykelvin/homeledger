<?php declare(strict_types=1); ?>
<section class="auth-card">
    <p class="section-kicker">WELCOME BACK</p>
    <h1>Sign in</h1>
    <p>Use the email and password for your household.</p>
    <form method="post" class="auth-form" autocomplete="on">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="next" value="<?= e(safe_next_page(is_string($_GET['next'] ?? null) ? $_GET['next'] : null)) ?>">
        <label>
            <span>Email</span>
            <input name="email" type="email" value="<?= old_form('email') ?>" autocomplete="username" maxlength="190" required>
        </label>
        <label>
            <span>Password</span>
            <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <button class="primary-button" type="submit">Sign in</button>
    </form>
    <p class="auth-switch">New here? <a href="?page=register">Create a household</a>. If you were invited, use the link you were sent.</p>
</section>
