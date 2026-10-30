<?php
/** Page header and navigation. Set $pageTitle (and optionally $active) before including. */
$user = current_user();
$active = $active ?? '';
$navItem = function (string $key, string $href, string $icon, string $label) use ($active): string {
    $cls = $active === $key ? ' active' : '';
    return '<li class="nav-item"><a class="nav-link' . $cls . '" href="' . e(url($href)) . '"><i class="bi bi-' . $icon . ' me-1"></i>' . e($label) . '</a></li>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($pageTitle ?? 'Home') . ' | ' . APP_NAME) ?></title>
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="<?= $user ? 'app' : 'guest' ?>">
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg navbar-dark pg-navbar sticky-top">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand fw-bold" href="<?= e(url('dashboard.php')) ?>">
            <i class="bi bi-shield-check"></i> <?= e(APP_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                <?= $navItem('dashboard', 'dashboard.php', 'speedometer2', 'Dashboard') ?>
                <?= $navItem('statements', 'statements/index.php', 'file-earmark-text', 'Statements') ?>
                <?= $navItem('transactions', 'transactions.php', 'list-ul', 'Transactions') ?>
                <?= $navItem('flagged', 'flagged.php', 'exclamation-triangle', 'Fraud Alerts') ?>
                <?= $navItem('reports', 'reports/index.php', 'bar-chart-line', 'Reports') ?>
                <?php if ($user['role'] === 'Admin'): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= $active === 'admin' ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear me-1"></i>Admin
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= e(url('admin/index.php')) ?>"><i class="bi bi-grid me-2"></i>Overview</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('admin/users.php')) ?>"><i class="bi bi-people me-2"></i>Users</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('admin/rules.php')) ?>"><i class="bi bi-sliders me-2"></i>Fraud Rules</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('admin/blacklist.php')) ?>"><i class="bi bi-slash-circle me-2"></i>Blacklist</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('admin/categories.php')) ?>"><i class="bi bi-tags me-2"></i>Categories</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('admin/logs.php')) ?>"><i class="bi bi-journal-text me-2"></i>Activity Log</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i><?= e($user['full_name']) ?>
                        <span class="badge bg-light text-dark ms-1"><?= e($user['role']) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= e(url('auth/logout.php')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>
<main class="<?= $user ? 'container-fluid px-lg-4 py-4' : '' ?>">
<?php foreach (flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show <?= $user ? '' : 'mx-auto mt-3 guest-alert' ?>" role="alert">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>
