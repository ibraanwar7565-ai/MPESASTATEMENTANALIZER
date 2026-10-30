<?php
require_once __DIR__ . '/../config/config.php';

if (current_user()) {
    redirect('dashboard.php');
}

$data = ['full_name' => '', 'email' => '', 'phone' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $data['full_name'] = trim($_POST['full_name'] ?? '');
    $data['email'] = strtolower(trim($_POST['email'] ?? ''));
    $data['phone'] = preg_replace('/\s+/', '', $_POST['phone'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (mb_strlen($data['full_name']) < 3 || mb_strlen($data['full_name']) > 100) {
        $errors[] = 'Enter your full name (3 to 100 characters).';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid e-mail address.';
    }
    if ($data['phone'] !== '' && !preg_match('/^(\+?254|0)[17]\d{8}$/', $data['phone'])) {
        $errors[] = 'Enter a valid Safaricom number, e.g. 0712345678.';
    }
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'The password must have at least 8 characters, including letters and numbers.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    if (!$errors) {
        $st = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $st->execute([$data['email']]);
        if ($st->fetchColumn()) {
            $errors[] = 'An account with this e-mail address already exists.';
        }
    }
    if (!$errors) {
        $st = db()->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, 'User', 'Active', ?)");
        $st->execute([$data['full_name'], $data['email'], $data['phone'], password_hash($password, PASSWORD_BCRYPT), now()]);
        log_activity((int) db()->lastInsertId(), 'Registered a new account');
        flash('success', 'Account created. You can now log in.');
        redirect('auth/login.php');
    }
}

$pageTitle = 'Create account';
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
            <h2 class="h5 fw-bold mb-3">Create an account</h2>
            <?php if ($errors): ?>
                <div class="alert alert-danger py-2"><ul class="mb-0 ps-3">
                    <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                </ul></div>
            <?php endif; ?>
            <form method="post" data-match-passwords>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="full_name">Full name</label>
                    <input class="form-control" id="full_name" name="full_name" value="<?= e($data['full_name']) ?>" required minlength="3" maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">E-mail address</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= e($data['email']) ?>" required maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="phone">M-Pesa phone number <span class="text-muted small">(optional)</span></label>
                    <input class="form-control" id="phone" name="phone" value="<?= e($data['phone']) ?>" placeholder="0712345678" maxlength="13">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required minlength="8">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="confirm_password">Confirm password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                    <div class="form-text">At least 8 characters with letters and numbers.</div>
                </div>
                <button class="btn btn-pg w-100" type="submit"><i class="bi bi-person-plus me-1"></i>Create account</button>
            </form>
            <p class="text-center small mt-3 mb-0">Already registered? <a href="<?= e(url('auth/login.php')) ?>" class="text-pg fw-semibold">Log in</a></p>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
