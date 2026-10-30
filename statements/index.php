<?php
require_once __DIR__ . '/../config/config.php';
$user = require_login();

// Delete a statement together with its transactions and flags (ON DELETE CASCADE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    verify_csrf();
    $id = (int) $_POST['delete'];
    $sql = 'DELETE FROM statements WHERE statement_id = ?' . ($user['role'] === 'Admin' ? '' : ' AND user_id = ?');
    $st = db()->prepare($sql);
    $st->execute($user['role'] === 'Admin' ? [$id] : [$id, $user['user_id']]);
    if ($st->rowCount()) {
        log_activity((int) $user['user_id'], "Deleted statement #$id");
        flash('success', 'The statement and all its transactions were deleted.');
    } else {
        flash('danger', 'Statement not found.');
    }
    redirect('statements/index.php');
}

$params = [];
$where = $user['role'] === 'Admin' ? '' : 'WHERE s.user_id = ?';
if ($where) {
    $params[] = $user['user_id'];
}
$st = db()->prepare("SELECT s.*, u.full_name,
                            (SELECT COUNT(*) FROM transactions t WHERE t.statement_id = s.statement_id AND t.risk_score >= " . MEDIUM_RISK . ") AS flagged
                     FROM statements s JOIN users u ON u.user_id = s.user_id $where
                     ORDER BY s.uploaded_at DESC, s.statement_id DESC");
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = 'Statements';
$active = 'statements';
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div>
        <h1 class="h3 page-title">Statements</h1>
        <p class="page-sub"><?= $user['role'] === 'Admin' ? 'All statements uploaded by all users.' : 'Statements you have uploaded and analysed.' ?></p>
    </div>
    <a href="<?= e(url('statements/upload.php')) ?>" class="btn btn-pg"><i class="bi bi-cloud-upload me-1"></i>Upload statement</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>#</th><th>File</th><?php if ($user['role'] === 'Admin'): ?><th>Owner</th><?php endif; ?>
                <th>Type</th><th>Period</th><th class="text-center">Transactions</th><th class="text-center">Flagged</th><th>Uploaded</th><th class="text-end">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $s): ?>
                <tr>
                    <td><?= (int) $s['statement_id'] ?></td>
                    <td class="fw-semibold"><?= e($s['file_name']) ?></td>
                    <?php if ($user['role'] === 'Admin'): ?><td><?= e($s['full_name']) ?></td><?php endif; ?>
                    <td><span class="badge bg-light text-dark border"><?= e($s['source_type']) ?></span></td>
                    <td class="text-nowrap small"><?= e(date('d M Y', strtotime($s['period_start']))) ?> &ndash; <?= e(date('d M Y', strtotime($s['period_end']))) ?></td>
                    <td class="text-center"><?= (int) $s['total_records'] ?></td>
                    <td class="text-center"><?= $s['flagged'] ? '<span class="badge bg-danger">' . (int) $s['flagged'] . '</span>' : '0' ?></td>
                    <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime($s['uploaded_at']))) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('dashboard.php?statement=' . $s['statement_id'])) ?>" title="Dashboard"><i class="bi bi-speedometer2"></i></a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('transactions.php?statement=' . $s['statement_id'])) ?>" title="Transactions"><i class="bi bi-list-ul"></i></a>
                        <form method="post" class="d-inline" data-confirm="Delete this statement and all its transactions? This cannot be undone.">
                            <?= csrf_field() ?>
                            <button name="delete" value="<?= (int) $s['statement_id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="9" class="text-center text-muted py-5">No statements uploaded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
