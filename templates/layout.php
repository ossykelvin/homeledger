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
    <link rel="icon" href="assets/icons/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
    <script>
        document.documentElement.dataset.theme = localStorage.getItem('homeledger-theme') ||
            (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    </script>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
        <div class="brand-lockup">
            <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
            <span><strong><?= e((string) config('app.name')) ?></strong><small>Home money, clearly.</small></span>
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
        </nav>
        <div class="sidebar-note">
            <span class="status-dot"></span>
            <p><strong>Private by default</strong><small>Your data stays in your MySQL database.</small></p>
        </div>
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
                <button class="primary-button add-entry-button" type="button" data-open-dialog="transaction-dialog">
                    <svg aria-hidden="true"><use href="assets/icons/sprite.svg#plus"></use></svg>
                    <span>Add entry</span>
                </button>
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
<?php endif; ?>

<div class="dialog-backdrop" data-dialog-backdrop hidden></div>
<script src="assets/app.js" defer></script>
</body>
</html>
