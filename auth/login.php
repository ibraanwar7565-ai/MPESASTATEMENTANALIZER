<?php
require_once __DIR__ . '/../config/config.php';

if (current_user()) {
    redirect('dashboard.php');
}

const MAX_ATTEMPTS = 5;
const LOCK_MINUTES = 15;
$email = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Account lockout: count failed attempts for this e-mail in the last 15 minutes
    $since = date('Y-m-d H:i:s', time() - LOCK_MINUTES * 60);
    $st = db()->prepare("SELECT COUNT(*) FROM activity_log WHERE action = ? AND created_at >= ?");
    $st->execute(['Failed login: ' . $email, $since]);
    $failed = (int) $st->fetchColumn();

    if ($email === '' || $password === '') {
        $error = 'Enter your e-mail address and password.';
    } elseif ($failed >= MAX_ATTEMPTS) {
        $error = 'Too many failed attempts. Your account is locked for ' . LOCK_MINUTES . ' minutes.';
    } else {
        $st = db()->prepare("SELECT user_id, password_hash, status, full_name FROM users WHERE email = ?");
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u && password_verify($password, $u['password_hash'])) {
            if ($u['status'] !== 'Active') {
                $error = 'Your account has been deactivated. Contact the administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $u['user_id'];
                log_activity((int) $u['user_id'], 'Logged in');
                flash('success', 'Welcome back, ' . $u['full_name'] . '.');
                redirect('dashboard.php');
            }
        } else {
            log_activity($u ? (int) $u['user_id'] : null, 'Failed login: ' . $email);
            $left = MAX_ATTEMPTS - $failed - 1;
            $error = 'Incorrect e-mail or password.' . ($left > 0 ? " $left attempt(s) left." : ' Your account is now locked for ' . LOCK_MINUTES . ' minutes.');
        }
    }
}

$pageTitle = 'Log in';
require APP_ROOT . '/includes/header.php';
?>
<div class="auth-wrap">
    <div class="w-100" style="max-width:440px">
        <div class="auth-brand">
            <div class="display-5 mb-1"><i class="bi bi-shield-check"></i></div>
            <h1 class="h3 mb-0"><?= e(APP_NAME) ?></h1>
            <div class="small opacity-75"><?= e(APP_TAGLINE) ?></div>
        </div>
        <div class="card auth-card p-4">
            <h2 class="h5 fw-bold mb-3">Log in to your account</h2>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="email">E-mail address</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button class="btn btn-pg w-100" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Log in</button>
            </form>
            <p class="text-center small mt-3 mb-0">No account yet? <a href="<?= e(url('auth/register.php')) ?>" class="text-pg fw-semibold">Create one</a></p>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
