<?php
/**
 * Log in page.
 * Checks the e-mail and password, locks the account for 15 minutes after 5 failed attempts,
 * and starts a fresh session for the user. Layout: the logic at the top, the HTML below.
 */
require_once __DIR__ . '/../config/config.php';

// Already logged in? Go straight to the dashboard.
if (current_user()) {
    redirect('dashboard.php');
}

// Lockout settings: 5 wrong passwords within 15 minutes locks the account for 15 minutes.
const MAX_ATTEMPTS = 5;
const LOCK_MINUTES = 15;
$email = '';
$error = '';

// This block runs only when the Log in button is pressed (the form is sent with POST).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reject the form if it did not come from this page (CSRF check).
    verify_csrf();
    // Clean the input: no spaces and lower case, so "Brian@Example.com " still matches.
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
        // Find the account. The ? is a placeholder: the e-mail is sent separately from the SQL,
        // so typing SQL into the e-mail box cannot change the query (SQL injection protection).
        $st = db()->prepare("SELECT user_id, password_hash, status, full_name FROM users WHERE email = ?");
        $st->execute([$email]);
        $u = $st->fetch();
        // password_verify() hashes the typed password the same way and compares it with the stored bcrypt hash.
        // The real password is never stored and never compared as plain text.
        if ($u && password_verify($password, $u['password_hash'])) {
            if ($u['status'] !== 'Active') {
                $error = 'Your account has been deactivated. Contact the administrator.';
            } else {
                // New session id after login, so an id planted by an attacker before login becomes useless (session fixation).
                session_regenerate_id(true);
                // From now on every page knows who this user is.
                $_SESSION['user_id'] = (int) $u['user_id'];
                log_activity((int) $u['user_id'], 'Logged in');
                flash('success', 'Welcome back, ' . $u['full_name'] . '.');
                redirect('dashboard.php');
            }
        } else {
            // Wrong password or unknown e-mail: record the failure (this is what the lockout counts),
            // and show the same message in both cases, so nobody can find out which e-mails are registered.
            log_activity($u ? (int) $u['user_id'] : null, 'Failed login: ' . $email);
            $left = MAX_ATTEMPTS - $failed - 1;
            $error = 'Invalid email or password.' . ($left > 0 ? " $left attempt(s) left." : ' Your account is now locked for ' . LOCK_MINUTES . ' minutes.');
        }
    }
}

$pageTitle = 'Log in';
require APP_ROOT . '/includes/header.php';
?>
<?php
// Optional partner logo: place the official file at assets/img/mpesa-logo.png (or .svg) and it appears here.
$mpesaLogo = '';
foreach (['png', 'svg', 'jpg', 'webp'] as $x) {
    if (is_file(APP_ROOT . '/assets/img/mpesa-logo.' . $x)) { $mpesaLogo = url('assets/img/mpesa-logo.' . $x); break; }
}
?>
<div class="auth-wrap">
    <div class="auth-split">
        <section class="auth-hero">
            <div class="auth-brand text-start">
                <div class="display-5 mx-0"><i class="bi bi-shield-check"></i></div>
                <h1 class="h2 mb-1"><?= e(APP_NAME) ?></h1>
                <div class="opacity-75"><?= e(APP_TAGLINE) ?></div>
            </div>
            <ul class="auth-points">
                <li><i class="bi bi-file-earmark-lock"></i><span>Upload your password-protected M-PESA statement (PDF or CSV)</span></li>
                <li><i class="bi bi-pie-chart"></i><span>See where your money goes: categories, charges and cash flow</span></li>
                <li><i class="bi bi-exclamation-triangle"></i><span>Get alerts on suspicious transactions scored by 8 fraud rules</span></li>
            </ul>
            <div class="auth-partner">
                <?php if ($mpesaLogo): ?>
                    <img src="<?= e($mpesaLogo) ?>" alt="M-PESA">
                <?php else: ?>
                    <span class="partner-text">M-PESA</span>
                <?php endif; ?>
                <span>Built for M-PESA statements from Safaricom</span>
            </div>
        </section>
        <section class="auth-form">
            <div class="card auth-card p-4">
                <h2 class="h5 fw-bold mb-1">Log in to your account</h2>
                <p class="text-muted small mb-3">Analyse your M-PESA statement securely.</p>
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?= e($error) ?></div>
                <?php endif; ?>
                <?php // The login form. csrf_field() adds the hidden security token. ?>
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
                <p class="text-center small mt-3 mb-0">No account yet? <a href="<?= e(url('auth/register.php')) ?>" class="text-pg fw-semibold">Register now</a></p>
            </div>
        </section>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
