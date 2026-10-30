<?php
require_once __DIR__ . '/../config/config.php';
$admin = require_admin();
$errors = [];
$form = ['identifier' => strtolower(trim($_GET['identifier'] ?? '')), 'entity_type' => 'Phone', 'reason' => ''];
$editId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        db()->prepare('DELETE FROM blacklist WHERE blacklist_id = ?')->execute([(int) $_POST['blacklist_id']]);
        log_activity((int) $admin['user_id'], 'Removed blacklist entry #' . (int) $_POST['blacklist_id']);
        flash('success', 'Entry removed from the blacklist.');
        redirect('admin/blacklist.php');
    }
    $form = [
        'identifier'  => strtolower(preg_replace('/\s+/', '', $_POST['identifier'] ?? '')),
        'entity_type' => in_array($_POST['entity_type'] ?? '', ['Phone', 'Till', 'Paybill'], true) ? $_POST['entity_type'] : 'Phone',
        'reason'      => trim($_POST['reason'] ?? ''),
    ];
    $editId = (int) ($_POST['blacklist_id'] ?? 0);
    if (!preg_match('/^[0-9*x+]{5,20}$/', $form['identifier'])) {
        $errors[] = 'Enter the number as it appears on statements, e.g. 0790***066 or 888880.';
    }
    if (mb_strlen($form['reason']) < 5) {
        $errors[] = 'Give a short reason (at least 5 characters).';
    }
    if (!$errors) {
        try {
            if ($editId) {
                db()->prepare('UPDATE blacklist SET identifier = ?, entity_type = ?, reason = ? WHERE blacklist_id = ?')
                    ->execute([$form['identifier'], $form['entity_type'], mb_substr($form['reason'], 0, 255), $editId]);
                log_activity((int) $admin['user_id'], "Updated blacklist entry {$form['identifier']}");
                flash('success', 'Blacklist entry updated.');
            } else {
                db()->prepare('INSERT INTO blacklist (identifier, entity_type, reason, added_by, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$form['identifier'], $form['entity_type'], mb_substr($form['reason'], 0, 255), $admin['user_id'], now()]);
                log_activity((int) $admin['user_id'], "Blacklisted {$form['identifier']}");
                flash('success', "{$form['identifier']} added to the blacklist. Re-score statements to apply it to existing data.");
            }
            redirect('admin/blacklist.php');
        } catch (PDOException $ex) {
            $errors[] = 'That number is already on the blacklist.';
        }
    }
} elseif ($editId) {
    $st = db()->prepare('SELECT * FROM blacklist WHERE blacklist_id = ?');
    $st->execute([$editId]);
    $form = $st->fetch() ?: $form;
}

$rows = db()->query('SELECT b.*, u.full_name FROM blacklist b LEFT JOIN users u ON u.user_id = b.added_by ORDER BY b.blacklist_id DESC')->fetchAll();

$pageTitle = 'Blacklist';
$active = 'admin';
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 page-title">Blacklist</h1>
<p class="page-sub">Numbers, tills and paybills reported for fraud. Any payment to them triggers rule R5.</p>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><?= $editId ? 'Edit entry' : 'Add to blacklist' ?></div>
            <div class="card-body">
                <?php if ($errors): ?><div class="alert alert-danger py-2 small"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="blacklist_id" value="<?= $editId ?>">
                    <div class="mb-2"><label class="form-label small" for="identifier">Number / till / paybill</label>
                        <input class="form-control" id="identifier" name="identifier" value="<?= e($form['identifier']) ?>" placeholder="0790***066" required></div>
                    <div class="mb-2"><label class="form-label small" for="entity_type">Type</label>
                        <select class="form-select" id="entity_type" name="entity_type">
                            <?php foreach (['Phone', 'Till', 'Paybill'] as $t): ?><option <?= $form['entity_type'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="mb-3"><label class="form-label small" for="reason">Reason</label>
                        <textarea class="form-control" id="reason" name="reason" rows="3" maxlength="255" required><?= e($form['reason']) ?></textarea></div>
                    <button class="btn btn-pg"><?= $editId ? 'Save changes' : 'Add to blacklist' ?></button>
                    <?php if ($editId): ?><a href="<?= e(url('admin/blacklist.php')) ?>" class="btn btn-link">Cancel</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Identifier</th><th>Type</th><th>Reason</th><th>Added by</th><th>Date</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $b): ?>
                        <tr>
                            <td class="font-monospace fw-semibold"><?= e($b['identifier']) ?></td>
                            <td><?= e($b['entity_type']) ?></td>
                            <td class="small"><?= e($b['reason']) ?></td>
                            <td class="small"><?= e($b['full_name'] ?? '-') ?></td>
                            <td class="small text-nowrap"><?= e(date('d M Y', strtotime($b['created_at']))) ?></td>
                            <td class="text-end text-nowrap">
                                <a href="?edit=<?= (int) $b['blacklist_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                <form method="post" class="d-inline" data-confirm="Remove this entry from the blacklist?">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="blacklist_id" value="<?= (int) $b['blacklist_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">The blacklist is empty.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
