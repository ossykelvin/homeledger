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
<body class="auth-body">
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="auth-shell">
    <header class="auth-toolbar">
        <button class="icon-button theme-toggle" type="button" aria-label="Change colour theme">
            <svg class="theme-icon moon" aria-hidden="true"><use href="assets/icons/sprite.svg#moon"></use></svg>
            <svg class="theme-icon sun" aria-hidden="true"><use href="assets/icons/sprite.svg#sun"></use></svg>
        </button>
    </header>
    <main id="main-content" class="auth-main" tabindex="-1">
        <div class="auth-brand">
            <img id="brand-logo-img" class="brand-logo-img" src="assets/brand/logo-dark.png" width="2847" height="425" alt="HomeLedger">
            <script>
                (function () {
                    var img = document.getElementById('brand-logo-img');
                    if (img && document.documentElement.dataset.theme === 'light') {
                        img.src = 'assets/brand/logo-light.png';
                    }
                })();
            </script>
            <p>Home money, clearly.</p>
        </div>
        <?php if ($flash): ?>
            <div class="toast auth-toast <?= e($flash['type']) ?>" role="status" data-toast>
                <span><?= e($flash['message']) ?></span>
                <button type="button" aria-label="Dismiss message">×</button>
            </div>
        <?php endif; ?>
        <?php require __DIR__ . '/pages/' . $page . '.php'; ?>
    </main>
</div>
<script src="assets/app.js" defer></script>
</body>
</html>
