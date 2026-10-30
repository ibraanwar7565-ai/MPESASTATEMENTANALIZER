<?php
require_once __DIR__ . '/../config/config.php';
$admin = require_admin();
$errors = [];
$editId = (int) ($_GET['edit'] ?? 0);
$form = ['name' => '', 'keyword_pattern' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int) $_POST['category_id'];
        db()->prepare('UPDATE transactions SET category_id = NULL WHERE category_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM categories WHERE category_id = ?')->execute([$id]);
        log_activity((int) $admin['user_id'], "Deleted category #$id");
        flash('success', 'Category deleted. Its transactions are now uncategorised.');
        redirect('admin/categories.php');
    }
    $editId = (int) ($_POST['category_id'] ?? 0);
    $form = ['name' => trim($_POST['name'] ?? ''), 'keyword_pattern' => trim($_POST['keyword_pattern'] ?? '')];
    if (mb_strlen($form['name']) < 2 || mb_strlen($form['name']) > 50) {
        $errors[] = 'Enter a category name (2 to 50 characters).';
    }
    if (Categorizer::compile($form['keyword_pattern']) === null) {
        $errors[] = 'Enter at least one keyword. Separate keywords with "|".';
    }
    if (!$errors) {
        try {
            if ($editId) {
                db()->prepare('UPDATE categories SET name = ?, keyword_pattern = ? WHERE category_id = ?')->execute([$form['name'], $form['keyword_pattern'], $editId]);
            } else {
                db()->prepare('INSERT INTO categories (name, keyword_pattern) VALUES (?, ?)')->execute([$form['name'], $form['keyword_pattern']]);
            }
            log_activity((int) $admin['user_id'], ($editId ? 'Updated' : 'Created') . " category {$form['name']}");
            flash('success', 'Category saved. Re-score statements to re-categorise existing transactions.');
            redirect('admin/categories.php');
        } catch (PDOException $ex) {
            $errors[] = 'A category with that name already exists.';
        }
    }
} elseif ($editId) {
    $st = db()->prepare('SELECT * FROM categories WHERE category_id = ?');
    $st->execute([$editId]);
    $form = $st->fetch() ?: $form;
}

$rows = db()->query('SELECT c.*, (SELECT COUNT(*) FROM transactions t WHERE t.category_id = c.category_id) AS used
                     FROM categories c ORDER BY c.category_id')->fetchAll();

$pageTitle = 'Categories';
$active = 'admin';
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 page-title">Transaction categories</h1>
<p class="page-sub">Transactions are matched against the keywords from top to bottom; the first match wins.</p>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><?= $editId ? 'Edit category' : 'New category' ?></div>
            <div class="card-body">
                <?php if ($errors): ?><div class="alert alert-danger py-2 small"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="category_id" value="<?= $editId ?>">
                    <div class="mb-2"><label class="form-label small" for="name">Name</label>
                        <input class="form-control" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="50" required></div>
                    <div class="mb-3"><label class="form-label small" for="keyword_pattern">Keywords (separate with |)</label>
                        <input class="form-control" id="keyword_pattern" name="keyword_pattern" value="<?= e($form['keyword_pattern']) ?>" maxlength="255" placeholder="Pay Bill|Paybill" required></div>
                    <button class="btn btn-pg"><?= $editId ? 'Save changes' : 'Add category' ?></button>
                    <?php if ($editId): ?><a href="<?= e(url('admin/categories.php')) ?>" class="btn btn-link">Cancel</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Order</th><th>Name</th><th>Keywords</th><th class="text-center">Transactions</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $i => $c): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td class="fw-semibold"><?= e($c['name']) ?></td>
                            <td><?php foreach (explode('|', $c['keyword_pattern']) as $k): ?><span class="rule-chip"><?= e(trim($k)) ?></span><?php endforeach; ?></td>
                            <td class="text-center"><?= (int) $c['used'] ?></td>
                            <td class="text-end text-nowrap">
                                <a href="?edit=<?= (int) $c['category_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                <form method="post" class="d-inline" data-confirm="Delete this category? Its transactions will become uncategorised.">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="category_id" value="<?= (int) $c['category_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
