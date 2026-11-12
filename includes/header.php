<?php
/** Page header and navigation. Set $pageTitle (and optionally $active) before including. */
$user = current_user();
$active = $active ?? '';
$navItem = function (string $key, string $href, string $icon, string $label) use ($active): string {
    $cls = $active === $key ? ' active' : '';
    return '<li><a class="side-link' . $cls . '" href="' . e(url($href)) . '"><i class="bi bi-' . $icon . '"></i><span>' . e($label) . '</span></a></li>';
};
$here = basename($_SERVER['SCRIPT_NAME'] ?? '');
$adminItem = function (string $file, string $icon, string $label) use ($active, $here): string {
    $cls = ($active === 'admin' && $here === $file) ? ' active' : '';
    return '<li><a class="side-link' . $cls . '" href="' . e(url('admin/' . $file)) . '"><i class="bi bi-' . $icon . '"></i><span>' . e($label) . '</span></a></li>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($pageTitle ?? 'Home') . ' | ' . APP_NAME) ?></title>
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(url('assets/fonts/figtree-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=2">
</head>
<body class="<?= $user ? 'app' : 'guest' ?>">
<?php if ($user): ?>
<div class="pg-shell">
    <aside class="pg-side offcanvas-lg offcanvas-start" tabindex="-1" id="sideNav" aria-label="Main navigation">
        <a class="side-brand" href="<?= e(url('dashboard.php')) ?>">
            <span class="brand-mark"><i class="bi bi-shield-check"></i></span>
            <span><?= e(APP_NAME) ?><small>Fraud detection</small></span>
        </a>
        <nav class="side-scroll">
            <div class="side-label">Menu</div>
            <ul class="side-nav">
                <?= $navItem('dashboard', 'dashboard.php', 'grid-1x2', 'Dashboard') ?>
                <?= $navItem('upload', 'statements/upload.php', 'cloud-arrow-up', 'Upload Statement') ?>
                <?= $navItem('statements', 'statements/index.php', 'file-earmark-text', 'Statements') ?>
                <?= $navItem('transactions', 'transactions.php', 'arrow-left-right', 'Transactions') ?>
                <?= $navItem('flagged', 'flagged.php', 'exclamation-triangle', 'Flagged') ?>
                <?= $navItem('reports', 'reports/index.php', 'bar-chart-line', 'Reports') ?>
            </ul>
            <?php if ($user['role'] === 'Admin'): ?>
            <div class="side-label">Admin</div>
            <ul class="side-nav">
                <?= $adminItem('index.php', 'speedometer2', 'Overview') ?>
                <?= $adminItem('users.php', 'people', 'Users') ?>
                <?= $adminItem('rules.php', 'sliders', 'Fraud Rules') ?>
                <?= $adminItem('blacklist.php', 'slash-circle', 'Blacklist') ?>
                <?= $adminItem('categories.php', 'tags', 'Categories') ?>
                <?= $adminItem('logs.php', 'journal-text', 'Logs') ?>
                <?= $adminItem('status.php', 'activity', 'System Status') ?>
            </ul>
            <?php endif; ?>
        </nav>
        <div class="side-foot">MKU BBIT Project &middot; <?= date('Y') ?></div>
    </aside>
    <div class="pg-main">
        <header class="pg-top">
            <button class="btn btn-light d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sideNav" aria-label="Open menu"><i class="bi bi-list"></i></button>
            <div class="top-title"><?= e($pageTitle ?? '') ?></div>
            <div class="dropdown ms-auto">
                <a class="user-chip dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                    <span class="avatar"><?= e(strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
                    <span class="user-name"><?= e($user['full_name']) ?></span>
                    <span class="role-tag"><?= e($user['role']) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>"><i class="bi bi-person me-2"></i>My Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?= e(url('auth/logout.php')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
                </ul>
            </div>
        </header>
<?php endif; ?>
<main class="<?= $user ? 'pg-content' : '' ?>">
<?php foreach (flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show <?= $user ? '' : 'mx-auto mt-3 guest-alert' ?>" role="alert">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>
