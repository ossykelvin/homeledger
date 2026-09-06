<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#080b0f">
    <meta name="description" content="Private home income and expense tracking with one-time and recurring entries.">
    <title><?= e($pageTitle) ?> · <?= e((string) config('app.name')) ?></title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/brand/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/brand/favicon-16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/brand/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/brand/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/app.css">
    <script>
        (function () {
            var theme = localStorage.getItem('homeledger-theme') ||
                (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            document.documentElement.dataset.theme = theme;
        })();
    </script>
</head>
<body<?php if (!empty($currentUser)): ?> data-household-version="<?= e((string) ((int) ($currentUser['household_state_version'] ?? 0))) ?>"<?php endif; ?>>
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
        <div class="brand-lockup">
            <a class="brand-logo" href="?page=dashboard" aria-label="HomeLedger">
                <img id="brand-logo-img" class="brand-logo-img" src="assets/brand/logo-dark.png" width="2847" height="425" alt="">
            </a>
            <script>
                (function () {
                    var img = document.getElementById('brand-logo-img');
                    if (img && document.documentElement.dataset.theme === 'light') {
                        img.src = 'assets/brand/logo-light.png';
                    }
                })();
            </script>
            <small>Home money, clearly.</small>
        </div>
        <nav class="nav-list">
            <a href="?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">
                <svg aria-hidden="true"><use href="assets/icons/sprite.svg#home"></use></svg><span>Overview</span>
            </a>
            <a href="?page=transactions" class="<?= $page === 'transactions' ? 'active' : '' ?>">
                <svg aria-hidden="true"><use href="assets/icons/sprite.svg#list"></use></svg><span>Transactions</span>
            </a>
            <a href="?page=recurring" class="<?= $page === 'recurring' ? 'active' : '' ?>">
                <svg aria-hidden="true"><use href="assets/icons/sprite.svg#repeat"></use></svg><span>Recurring</span>
            </a>
            <a href="?page=categories" class="<?= $page === 'categories' ? 'active' : '' ?>">
                <svg aria-hidden="true"><use href="assets/icons/sprite.svg#tag"></use></svg><span>Categories</span>
            </a>
            <a href="?page=statement" class="<?= $page === 'statement' ? 'active' : '' ?>">
                <svg aria-hidden="true"><use href="assets/icons/sprite.svg#wallet"></use></svg><span>Statement</span>
            </a>
            <a href="?page=household" class="<?= $page === 'household' ? 'active' : '' ?>">
                <svg aria-hidden="true"><use href="assets/icons/sprite.svg#users"></use></svg><span>Household</span>
            </a>
        </nav>
        <?php if (!empty($currentUser)): ?>
            <div class="sidebar-account">
                <p><strong><?= e((string) ($currentUser['household_name'] ?? 'Household')) ?></strong><small><?= e((string) $currentUser['display_name']) ?></small></p>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="secondary-button sidebar-logout" type="submit">Sign out</button>
                </form>
            </div>
        <?php endif; ?>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button class="icon-button menu-toggle" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">
                <svg aria-hidden="true"><use href="assets/icons/sprite.svg#menu"></use></svg>
            </button>
            <div>
                <span class="eyebrow">HOUSEHOLD FINANCE</span>
                <h1><?= e($pageTitle) ?></h1>
            </div>
            <div class="topbar-actions">
                <span class="today"><?= e(date('D, j M')) ?></span>
                <button class="icon-button theme-toggle" type="button" aria-label="Change colour theme">
                    <svg class="theme-icon moon" aria-hidden="true"><use href="assets/icons/sprite.svg#moon"></use></svg>
                    <svg class="theme-icon sun" aria-hidden="true"><use href="assets/icons/sprite.svg#sun"></use></svg>
                </button>
                <?php if (!empty($currentUser)): ?>
                <button class="icon-button profile-toggle" type="button" data-open-dialog="profile-dialog" aria-haspopup="dialog" aria-expanded="false" aria-controls="profile-dialog" aria-label="Open profile settings">
                    <svg aria-hidden="true"><use href="assets/icons/sprite.svg#user"></use></svg>
                </button>
                <?php endif; ?>
                <?php if ($page !== 'household'): ?>
                <button class="primary-button add-entry-button" type="button" data-open-dialog="transaction-dialog">
                    <svg aria-hidden="true"><use href="assets/icons/sprite.svg#plus"></use></svg>
                    <span>Add entry</span>
                </button>
                <?php endif; ?>
            </div>
        </header>

        <main id="main-content" tabindex="-1">
            <?php if ($setupError): ?>
                <section class="setup-state" role="alert">
                    <span class="eyebrow">SETUP REQUIRED</span>
                    <h2>Connect the database to start tracking.</h2>
                    <p>Import <code>database/schema.sql</code>, check the database settings, then reload this page. The README includes steps for Docker, XAMPP and a standard PHP server.</p>
                </section>
            <?php else: ?>
                <?php if ($flash): ?>
                    <div class="toast <?= e($flash['type']) ?>" role="status" data-toast>
                        <span><?= e($flash['message']) ?></span>
                        <button type="button" aria-label="Dismiss message">×</button>
                    </div>
                <?php endif; ?>
                <?php require __DIR__ . '/pages/' . $page . '.php'; ?>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php if (!$setupError): ?>
    <?php require __DIR__ . '/partials/transaction-dialog.php'; ?>
    <?php if (!empty($currentUser)): ?>
        <?php require __DIR__ . '/partials/profile-popover.php'; ?>
    <?php endif; ?>
<?php endif; ?>

<div class="dialog-backdrop" data-dialog-backdrop hidden></div>
<script src="assets/app.js" defer></script>
</body>
</html>
