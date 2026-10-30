<?php
require_once __DIR__ . '/../config/config.php';
$admin = require_admin();

$search = trim($_GET['q'] ?? '');
$params = [];
$where = '';
if ($search !== '') {
    $where = 'WHERE l.action LIKE ? OR u.full_name LIKE ? OR l.ip_address LIKE ?';
    $params = ["%$search%", "%$search%", "%$search%"];
}
$st = db()->prepare("SELECT COUNT(*) FROM activity_log l LEFT JOIN users u ON u.user_id = l.user_id $where");
$st->execute($params);
$total = (int) $st->fetchColumn();
$perPage = 50;
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$st = db()->prepare("SELECT l.*, u.full_name, u.email FROM activity_log l LEFT JOIN users u ON u.user_id = l.user_id $where
                     ORDER BY l.log_id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = 'Activity log';
$active = 'admin';
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 page-title">Activity log</h1>
<p class="page-sub">Audit trail of logins, uploads, reviews and administrative changes (<?= number_format($total) ?> entries).</p>
<div class="card">
    <div class="card-header bg-white">
        <form method="get" class="d-flex gap-2" style="max-width:420px">
            <input name="q" value="<?= e($search) ?>" class="form-control form-control-sm" placeholder="Search action, user or IP address">
            <button class="btn btn-sm btn-outline-secondary">Search</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th>#</th><th>Date &amp; time</th><th>User</th><th>Action</th><th>IP address</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $l): ?>
                <tr class="<?= str_starts_with($l['action'], 'Failed login') ? 'table-warning' : '' ?>">
                    <td><?= (int) $l['log_id'] ?></td>
                    <td class="small text-nowrap"><?= e(date('d M Y H:i:s', strtotime($l['created_at']))) ?></td>
                    <td class="small"><?= e($l['full_name'] ?? '-') ?></td>
                    <td class="small"><?= e($l['action']) ?></td>
                    <td class="small font-monospace"><?= e($l['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <span class="small text-muted">Page <?= $page ?> of <?= $pages ?></span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?q=<?= e(urlencode($search)) ?>&page=<?= $page - 1 ?>">Previous</a></li>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="?q=<?= e(urlencode($search)) ?>&page=<?= $page + 1 ?>">Next</a></li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
