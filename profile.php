<?php
require_once __DIR__ . '/config/config.php';
$user = require_login();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'details') {
        $name = trim($_POST['full_name'] ?? '');
        $phone = preg_replace('/\s+/', '', $_POST['phone'] ?? '');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            $errors[] = 'Enter your full name (3 to 100 characters).';
        }
        if ($phone !== '' && !preg_match('/^(\+?254|0)[17]\d{8}$/', $phone)) {
            $errors[] = 'Enter a valid Safaricom number, e.g. 0712345678.';
        }
        if (!$errors) {
            db()->prepare('UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?')->execute([$name, $phone, $user['user_id']]);
            log_activity((int) $user['user_id'], 'Updated profile details');
            flash('success', 'Your details were updated.');
            redirect('profile.php');
        }
    } elseif ($action === 'password') {
        $st = db()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $st->execute([$user['user_id']]);
        $new = (string) ($_POST['password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $st->fetchColumn())) {
            $errors[] = 'Your current password is incorrect.';
        } elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $errors[] = 'The new password must have at least 8 characters, including letters and numbers.';
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            $errors[] = 'The two new passwords do not match.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([password_hash($new, PASSWORD_BCRYPT), $user['user_id']]);
            session_regenerate_id(true);
            log_activity((int) $user['user_id'], 'Changed password');
            flash('success', 'Your password was changed.');
            redirect('profile.php');
        }
    } elseif ($action === 'delete_data') {
        $st = db()->prepare('DELETE FROM statements WHERE user_id = ?');
        $st->execute([$user['user_id']]);
        log_activity((int) $user['user_id'], 'Deleted all own statement data (' . $st->rowCount() . ' statements)');
        flash('success', 'All your statements and transactions were deleted.');
        redirect('profile.php');
    }
}

$st = db()->prepare('SELECT COUNT(*) FROM statements WHERE user_id = ?');
$st->execute([$user['user_id']]);
$myStatements = (int) $st->fetchColumn();

$pageTitle = 'My profile';
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 page-title">My profile</h1>
<p class="page-sub">Manage your account details, password and data.</p>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Account details</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="details">
                    <div class="mb-3"><label class="form-label" for="full_name">Full name</label>
                        <input class="form-control" id="full_name" name="full_name" value="<?= e($user['full_name']) ?>" required maxlength="100"></div>
                    <div class="mb-3"><label class="form-label">E-mail address</label>
                        <input class="form-control" value="<?= e($user['email']) ?>" disabled></div>
                    <div class="mb-3"><label class="form-label" for="phone">Phone number</label>
                        <input class="form-control" id="phone" name="phone" value="<?= e($user['phone']) ?>" maxlength="13"></div>
                    <button class="btn btn-pg">Save details</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header">Change password</div>
            <div class="card-body">
                <form method="post" data-match-passwords>
                    <?= csrf_field() ?><input type="hidden" name="action" value="password">
                    <div class="mb-3"><label class="form-label" for="current_password">Current password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-sm-6"><label class="form-label" for="password">New password</label>
                            <input type="password" class="form-control" id="password" name="password" required minlength="8"></div>
                        <div class="col-sm-6"><label class="form-label" for="confirm_password">Confirm new password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8"></div>
                    </div>
                    <button class="btn btn-pg">Change password</button>
                </form>
            </div>
        </div>
        <div class="card border border-danger-subtle">
            <div class="card-header text-danger">My data</div>
            <div class="card-body">
                <p class="small">You have <strong><?= $myStatements ?></strong> statement(s) stored. Under the Data Protection Act, 2019 you can delete all of your statement data at any time.</p>
                <form method="post" data-confirm="Delete ALL your statements, transactions and alerts? This cannot be undone.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_data">
                    <button class="btn btn-outline-danger btn-sm" <?= $myStatements ? '' : 'disabled' ?>><i class="bi bi-trash me-1"></i>Delete all my statement data</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
