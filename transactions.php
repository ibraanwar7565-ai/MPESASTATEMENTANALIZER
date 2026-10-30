<?php
require_once __DIR__ . '/config/config.php';
$user = require_login();

$categories = db()->query('SELECT category_id, name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

// Update the category of one transaction (user correction)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transaction_id'])) {
    verify_csrf();
    $txId = (int) $_POST['transaction_id'];
    $catId = (int) ($_POST['category_id'] ?? 0);
    $catId = isset($categories[$catId]) ? $catId : null;
    $sql = 'UPDATE transactions SET category_id = ? WHERE transaction_id = ?'
         . ($user['role'] === 'Admin' ? '' : ' AND statement_id IN (SELECT statement_id FROM statements WHERE user_id = ?)');
    $st = db()->prepare($sql);
    $st->execute($user['role'] === 'Admin' ? [$catId, $txId] : [$catId, $txId, $user['user_id']]);
    if ($st->rowCount()) {
        log_activity((int) $user['user_id'], "Changed category of transaction #$txId");
        flash('success', 'Category updated.');
    }
    redirect('transactions.php?' . ($_POST['return'] ?? ''));
}

// ---------- Filters ----------
$f = [
    'statement' => (int) ($_GET['statement'] ?? 0),
    'category'  => (int) ($_GET['category'] ?? 0),
    'direction' => in_array($_GET['direction'] ?? '', ['IN', 'OUT'], true) ? $_GET['direction'] : '',
    'risk'      => in_array($_GET['risk'] ?? '', ['Low', 'Medium', 'High'], true) ? $_GET['risk'] : '',
    'q'         => trim($_GET['q'] ?? ''),
    'from'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
    'to'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
];
$params = [];
$where = scope_sql($user, $f['statement'] ?: null, $params);
if ($f['category']) { $where .= ' AND t.category_id = ?'; $params[] = $f['category']; }
if ($f['direction']) { $where .= ' AND t.direction = ?'; $params[] = $f['direction']; }
if ($f['risk']) { $where .= ' AND t.risk_level = ?'; $params[] = $f['risk']; }
if ($f['q'] !== '') { $where .= ' AND (t.details LIKE ? OR t.receipt_no LIKE ? OR t.counterparty LIKE ?)'; array_push($params, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
if ($f['from']) { $where .= ' AND t.completion_time >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
if ($f['to']) { $where .= ' AND t.completion_time <= ?'; $params[] = $f['to'] . ' 23:59:59'; }

$base = "FROM transactions t JOIN statements s ON s.statement_id = t.statement_id
         LEFT JOIN categories c ON c.category_id = t.category_id WHERE 1 = 1 $where";
$st = db()->prepare("SELECT COUNT(*) AS n,
                            COALESCE(SUM(CASE WHEN t.direction = 'IN' THEN t.amount END), 0) AS tin,
                            COALESCE(SUM(CASE WHEN t.direction = 'OUT' THEN t.amount END), 0) AS tout $base");
$st->execute($params);
$totals = $st->fetch();

$perPage = 50;
$pages = max(1, (int) ceil($totals['n'] / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$st = db()->prepare("SELECT t.*, c.name AS category $base ORDER BY t.completion_time DESC, t.transaction_id DESC
                     LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll();

$query = http_build_query(array_filter($f));
$statements = user_statements($user);

$pageTitle = 'Transactions';
$active = 'transactions';
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div>
        <h1 class="h3 page-title">Transactions</h1>
        <p class="page-sub"><?= number_format($totals['n']) ?> transactions &middot; in <?= e(money($totals['tin'])) ?> &middot; out <?= e(money($totals['tout'])) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('reports/export.php?type=transactions&format=csv&statement=' . $f['statement'])) ?>"><i class="bi bi-filetype-csv me-1"></i>Export CSV</a>
    </div>
</div>

<form method="get" class="card card-body mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Statement</label>
            <select name="statement" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($statements as $s): ?>
                    <option value="<?= (int) $s['statement_id'] ?>" <?= $f['statement'] === (int) $s['statement_id'] ? 'selected' : '' ?>>#<?= (int) $s['statement_id'] ?> &middot; <?= e($s['file_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Category</label>
            <select name="category" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($categories as $id => $name): ?>
                    <option value="<?= (int) $id ?>" <?= $f['category'] === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-1">
            <label class="form-label small mb-1">Type</label>
            <select name="direction" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="IN" <?= $f['direction'] === 'IN' ? 'selected' : '' ?>>In</option>
                <option value="OUT" <?= $f['direction'] === 'OUT' ? 'selected' : '' ?>>Out</option>
            </select>
        </div>
        <div class="col-6 col-md-1">
            <label class="form-label small mb-1">Risk</label>
            <select name="risk" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach (['Low', 'Medium', 'High'] as $r): ?>
                    <option <?= $f['risk'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-1"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?= e($f['from']) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-1"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?= e($f['to']) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label small mb-1">Search</label><input name="q" value="<?= e($f['q']) ?>" class="form-control form-control-sm" placeholder="Name, number or receipt"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-pg btn-sm"><i class="bi bi-funnel"></i> Filter</button></div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th>Receipt</th><th>Date &amp; time</th><th>Details</th><th>Category</th><th class="text-end">Amount</th><th class="text-end">Balance</th><th>Risk</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $t): ?>
                <tr class="<?= $t['risk_level'] === 'High' ? 'row-high' : ($t['risk_level'] === 'Medium' ? 'row-medium' : '') ?>">
                    <td class="font-monospace small"><?= e($t['receipt_no']) ?></td>
                    <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime($t['completion_time']))) ?></td>
                    <td class="small"><?= e($t['details']) ?></td>
                    <td style="min-width:160px">
                        <form method="post" class="d-flex gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="transaction_id" value="<?= (int) $t['transaction_id'] ?>">
                            <input type="hidden" name="return" value="<?= e($query . '&page=' . $page) ?>">
                            <select name="category_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Category">
                                <option value="">Uncategorised</option>
                                <?php foreach ($categories as $id => $name): ?>
                                    <option value="<?= (int) $id ?>" <?= (int) $t['category_id'] === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td class="text-end text-nowrap <?= $t['direction'] === 'IN' ? 'amount-in' : 'amount-out' ?>"><?= $t['direction'] === 'IN' ? '+' : '&minus;' ?><?= e(number_format((float) $t['amount'], 2)) ?></td>
                    <td class="text-end text-nowrap small"><?= e(number_format((float) $t['balance'], 2)) ?></td>
                    <td class="text-nowrap"><?= $t['direction'] === 'OUT' ? risk_badge($t['risk_level']) . ' <span class="small text-muted">' . (int) $t['risk_score'] . '</span>' : '<span class="text-muted small">&ndash;</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">No transactions match your filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <span class="small text-muted">Page <?= $page ?> of <?= $pages ?></span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= e($query . '&page=' . ($page - 1)) ?>">Previous</a></li>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= e($query . '&page=' . ($page + 1)) ?>">Next</a></li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
