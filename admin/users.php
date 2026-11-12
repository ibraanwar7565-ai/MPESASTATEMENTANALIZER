<?php
/**
 * Manage users (admin only): create, activate or deactivate, change role, reset password, delete.
 * The "action" field selects the operation.
 */
require_once __DIR__ . '/../config/config.php';
// Admins only
$admin = require_admin();
$errors = [];   // validation messages for the form

// Form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();                          // reject forms without a valid token
    $action = $_POST['action'] ?? '';       // create | toggle_status | toggle_role | reset_password | delete
    $id = (int) ($_POST['user_id'] ?? 0);   // target account

    // Create account (same rules as registration)
    if ($action === 'create') {
        $name = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));                // stored in lower case
        $role = ($_POST['role'] ?? '') === 'Admin' ? 'Admin' : 'User';   // anything else becomes User
        $password = (string) ($_POST['password'] ?? '');
        // Validation
        if (mb_strlen($name) < 3) { $errors[] = 'Enter the full name.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid e-mail address.'; }
        // at least 8 characters with letters and digits
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'The password must have at least 8 characters, including letters and numbers.';
        }
        if (!$errors) {
            // E-mail must not be registered already
            $st = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetchColumn()) { $errors[] = 'That e-mail address is already registered.'; }
        }
        if (!$errors) {
            // Save: bcrypt password hash, account active immediately
            db()->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, 'Active', ?)")
                ->execute([$name, $email, trim($_POST['phone'] ?? ''), password_hash($password, PASSWORD_BCRYPT), $role, now()]);
            // Activity log, message, reload (Post/Redirect/Get)
            log_activity((int) $admin['user_id'], "Created user $email ($role)");
            flash('success', "User $email created.");
            redirect('admin/users.php');
        }
    } elseif ($id === (int) $admin['user_id']) {
        // Admins cannot change their own account here
        flash('warning', 'You cannot change or delete your own administrator account here.');
        redirect('admin/users.php');
    } elseif ($action === 'toggle_status') {
        // Toggle Active / Inactive
        db()->prepare("UPDATE users SET status = CASE WHEN status = 'Active' THEN 'Inactive' ELSE 'Active' END WHERE user_id = ?")->execute([$id]);
        log_activity((int) $admin['user_id'], "Changed status of user #$id");
        flash('success', 'User status changed.');
        redirect('admin/users.php');
    } elseif ($action === 'toggle_role') {
        // Toggle User / Admin
        db()->prepare("UPDATE users SET role = CASE WHEN role = 'Admin' THEN 'User' ELSE 'Admin' END WHERE user_id = ?")->execute([$id]);
        log_activity((int) $admin['user_id'], "Changed role of user #$id");
        flash('success', 'User role changed.');
        redirect('admin/users.php');
    } elseif ($action === 'reset_password') {
        // Temporary password, shown once
        $temp = 'Pg' . random_int(100000, 999999) . 'x';   // e.g. Pg482913x
        // only the hash is stored
        db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([password_hash($temp, PASSWORD_BCRYPT), $id]);
        log_activity((int) $admin['user_id'], "Reset password of user #$id");
        flash('info', "Temporary password: $temp. Give it to the user and ask them to change it in My Profile.");
        redirect('admin/users.php');
    } elseif ($action === 'delete') {
        // Delete user
        // keep blacklist entries this user added
        db()->prepare('UPDATE blacklist SET added_by = NULL WHERE added_by = ?')->execute([$id]);
        // ON DELETE CASCADE removes their statements, transactions and flags
        db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$id]);
        log_activity((int) $admin['user_id'], "Deleted user #$id and all their data");
        flash('success', 'User and all their statements were deleted.');
        redirect('admin/users.php');
    }
}

// User list with search
$search = trim($_GET['q'] ?? '');   // ?q= from the search box
// Users with their number of uploaded statements
$st = db()->prepare("SELECT u.*, (SELECT COUNT(*) FROM statements s WHERE s.user_id = u.user_id) AS statements
                     FROM users u WHERE u.full_name LIKE ? OR u.email LIKE ? ORDER BY u.user_id");
$st->execute(["%$search%", "%$search%"]);   // search term bound as a parameter
$users = $st->fetchAll();

$pageTitle = 'Manage users';   // browser tab and top bar title
$active = 'admin';             // highlights the Admin menu
require APP_ROOT . '/includes/header.php';
?>
<?php // Heading and "Add user" button ?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div><h1 class="h3 page-title">Users</h1><p class="page-sub">Create, activate, deactivate and delete user accounts.</p></div>
    <button class="btn btn-pg" data-bs-toggle="collapse" data-bs-target="#newUser"><i class="bi bi-person-plus me-1"></i>Add user</button>
</div>
<?php // Validation errors ?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php // New user form, kept open when there are errors ?>
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

<?php // Search box and user table ?>
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
            <?php // No action buttons on the logged-in admin's own row ?>
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
                        <?php // Status, role and password buttons; the clicked button's value is the action ?>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                            <button name="action" value="toggle_status" class="btn btn-sm btn-outline-secondary" title="<?= $u['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-<?= $u['status'] === 'Active' ? 'pause-circle' : 'play-circle' ?>"></i></button>
                            <button name="action" value="toggle_role" class="btn btn-sm btn-outline-secondary" title="Make <?= $u['role'] === 'Admin' ? 'user' : 'administrator' ?>"><i class="bi bi-person-gear"></i></button>
                            <button name="action" value="reset_password" class="btn btn-sm btn-outline-secondary" title="Reset password"><i class="bi bi-key"></i></button>
                        </form>
                        <?php // Delete, after a confirm box (app.js) ?>
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
