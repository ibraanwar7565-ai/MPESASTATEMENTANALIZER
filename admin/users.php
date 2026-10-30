<?php
require_once __DIR__ . '/../config/config.php';
$admin = require_admin();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'create') {
        $name = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = ($_POST['role'] ?? '') === 'Admin' ? 'Admin' : 'User';
        $password = (string) ($_POST['password'] ?? '');
        if (mb_strlen($name) < 3) { $errors[] = 'Enter the full name.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid e-mail address.'; }
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'The password must have at least 8 characters, including letters and numbers.';
        }
        if (!$errors) {
            $st = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetchColumn()) { $errors[] = 'That e-mail address is already registered.'; }
        }
        if (!$errors) {
            db()->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, 'Active', ?)")
                ->execute([$name, $email, trim($_POST['phone'] ?? ''), password_hash($password, PASSWORD_BCRYPT), $role, now()]);
            log_activity((int) $admin['user_id'], "Created user $email ($role)");
            flash('success', "User $email created.");
            redirect('admin/users.php');
        }
    } elseif ($id === (int) $admin['user_id']) {
        flash('warning', 'You cannot change or delete your own administrator account here.');
        redirect('admin/users.php');
    } elseif ($action === 'toggle_status') {
        db()->prepare("UPDATE users SET status = CASE WHEN status = 'Active' THEN 'Inactive' ELSE 'Active' END WHERE user_id = ?")->execute([$id]);
        log_activity((int) $admin['user_id'], "Changed status of user #$id");
        flash('success', 'User status changed.');
        redirect('admin/users.php');
    } elseif ($action === 'toggle_role') {
        db()->prepare("UPDATE users SET role = CASE WHEN role = 'Admin' THEN 'User' ELSE 'Admin' END WHERE user_id = ?")->execute([$id]);
        log_activity((int) $admin['user_id'], "Changed role of user #$id");
        flash('success', 'User role changed.');
        redirect('admin/users.php');
    } elseif ($action === 'reset_password') {
        $temp = 'Pg' . random_int(100000, 999999) . 'x';
        db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([password_hash($temp, PASSWORD_BCRYPT), $id]);
        log_activity((int) $admin['user_id'], "Reset password of user #$id");
        flash('info', "Temporary password: $temp. Give it to the user and ask them to change it in My Profile.");
        redirect('admin/users.php');
    } elseif ($action === 'delete') {
        db()->prepare('UPDATE blacklist SET added_by = NULL WHERE added_by = ?')->execute([$id]);
        db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$id]);
        log_activity((int) $admin['user_id'], "Deleted user #$id and all their data");
        flash('success', 'User and all their statements were deleted.');
        redirect('admin/users.php');
    }
}

$search = trim($_GET['q'] ?? '');
$st = db()->prepare("SELECT u.*, (SELECT COUNT(*) FROM statements s WHERE s.user_id = u.user_id) AS statements
                     FROM users u WHERE u.full_name LIKE ? OR u.email LIKE ? ORDER BY u.user_id");
$st->execute(["%$search%", "%$search%"]);
$users = $st->fetchAll();

$pageTitle = 'Manage users';
$active = 'admin';
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div><h1 class="h3 page-title">Users</h1><p class="page-sub">Create, activate, deactivate and delete user accounts.</p></div>
    <button class="btn btn-pg" data-bs-toggle="collapse" data-bs-target="#newUser"><i class="bi bi-person-plus me-1"></i>Add user</button>
</div>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="collapse <?= $errors ? 'show' : '' ?> mb-3" id="newUser">
    <div class="card card-body">
        <form method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="col-md-3"><label class="form-label small">Full name</label><input name="full_name" class="form-control form-control-sm" required value="<?= e($_POST['full_name'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label small">E-mail</label><input type="email" name="email" class="form-control form-control-sm" required value="<?= e($_POST['email'] ?? '') ?>"></div>
            <div class="col-md-2"><label class="form-label small">Phone</label><input name="phone" class="form-control form-control-sm" value="<?= e($_POST['phone'] ?? '') ?>"></div>
            <div class="col-md-2"><label class="form-label small">Password</label><input type="password" name="password" class="form-control form-control-sm" required minlength="8"></div>
            <div class="col-md-1"><label class="form-label small">Role</label><select name="role" class="form-select form-select-sm"><option>User</option><option>Admin</option></select></div>
            <div class="col-md-1 d-grid"><button class="btn btn-pg btn-sm">Save</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white">
        <form method="get" class="d-flex gap-2" style="max-width:420px">
            <input name="q" value="<?= e($search) ?>" class="form-control form-control-sm" placeholder="Search by name or e-mail">
            <button class="btn btn-sm btn-outline-secondary">Search</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>#</th><th>Name</th><th>E-mail</th><th>Phone</th><th>Role</th><th>Status</th><th class="text-center">Statements</th><th>Joined</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $self = (int) $u['user_id'] === (int) $admin['user_id']; ?>
                <tr>
                    <td><?= (int) $u['user_id'] ?></td>
                    <td class="fw-semibold"><?= e($u['full_name']) ?><?= $self ? ' <span class="badge bg-info-subtle text-info-emphasis">you</span>' : '' ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e($u['phone']) ?></td>
                    <td><span class="badge <?= $u['role'] === 'Admin' ? 'bg-dark' : 'bg-light text-dark border' ?>"><?= e($u['role']) ?></span></td>
                    <td><?= status_badge($u['status']) ?></td>
                    <td class="text-center"><?= (int) $u['statements'] ?></td>
                    <td class="small text-nowrap"><?= e(date('d M Y', strtotime($u['created_at']))) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if (!$self): ?>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                            <button name="action" value="toggle_status" class="btn btn-sm btn-outline-secondary" title="<?= $u['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-<?= $u['status'] === 'Active' ? 'pause-circle' : 'play-circle' ?>"></i></button>
                            <button name="action" value="toggle_role" class="btn btn-sm btn-outline-secondary" title="Make <?= $u['role'] === 'Admin' ? 'user' : 'administrator' ?>"><i class="bi bi-person-gear"></i></button>
                            <button name="action" value="reset_password" class="btn btn-sm btn-outline-secondary" title="Reset password"><i class="bi bi-key"></i></button>
                        </form>
                        <form method="post" class="d-inline" data-confirm="Delete this user and ALL their statements? This cannot be undone.">
                            <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                            <button name="action" value="delete" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
