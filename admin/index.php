<?php
require_once __DIR__ . '/../config/config.php';
$admin = require_admin();

// Re-run categorisation and fraud scoring on every stored statement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rescore'])) {
    verify_csrf();
    $r = (new StatementImporter(db()))->rescore();
    log_activity((int) $admin['user_id'], "Re-scored all statements ({$r['statements']} statements, {$r['flagged']} flagged)");
    flash('success', "Re-scored {$r['statements']} statement(s) with the current rules: {$r['flagged']} transaction(s) flagged.");
    redirect('admin/index.php');
}

$q = fn(string $sql) => db()->query($sql)->fetchColumn();
$stats = [
    ['Users', $q('SELECT COUNT(*) FROM users'), 'people', 'admin/users.php'],
    ['Statements', $q('SELECT COUNT(*) FROM statements'), 'file-earmark-text', 'statements/index.php'],
    ['Transactions', $q('SELECT COUNT(*) FROM transactions'), 'list-ol', 'transactions.php'],
    ['Flagged', $q('SELECT COUNT(*) FROM transactions WHERE risk_score >= ' . MEDIUM_RISK), 'exclamation-triangle', 'flagged.php?status='],
    ['Confirmed fraud', $q("SELECT COUNT(DISTINCT transaction_id) FROM flagged_transactions WHERE review_status = 'Confirmed'"), 'shield-x', 'flagged.php?status=Confirmed'],
    ['Blacklisted numbers', $q('SELECT COUNT(*) FROM blacklist'), 'slash-circle', 'admin/blacklist.php'],
];

// How often each rule fired and how often users confirmed it (helps to tune weights)
$ruleStats = db()->query("SELECT r.rule_code, r.rule_name, r.weight, r.is_active,
                                 COUNT(f.flag_id) AS fired,
                                 SUM(CASE WHEN f.review_status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed,
                                 SUM(CASE WHEN f.review_status = 'Dismissed' THEN 1 ELSE 0 END) AS dismissed
                          FROM fraud_rules r LEFT JOIN flagged_transactions f ON f.rule_id = r.rule_id
                          GROUP BY r.rule_id, r.rule_code, r.rule_name, r.weight, r.is_active ORDER BY r.rule_code")->fetchAll();
$recent = db()->query('SELECT l.*, u.full_name FROM activity_log l LEFT JOIN users u ON u.user_id = l.user_id
                       ORDER BY l.log_id DESC LIMIT 8')->fetchAll();

$pageTitle = 'Administration';
$active = 'admin';
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div>
        <h1 class="h3 page-title">Administration</h1>
        <p class="page-sub">System overview, rule performance and recent activity.</p>
    </div>
    <form method="post" data-confirm="Re-run categorisation and fraud scoring on all statements with the current rules?">
        <?= csrf_field() ?>
        <button name="rescore" value="1" class="btn btn-pg"><i class="bi bi-arrow-repeat me-1"></i>Re-score all statements</button>
    </form>
</div>

<div class="row g-3 mb-3">
    <?php foreach ($stats as [$label, $value, $icon, $href]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(url($href)) ?>" class="text-decoration-none text-reset">
            <div class="card kpi h-100"><div class="card-body d-flex align-items-center gap-3">
                <div class="kpi-icon bg-pg-soft text-pg"><i class="bi bi-<?= $icon ?>"></i></div>
                <div><div class="kpi-label"><?= e($label) ?></div><div class="kpi-value"><?= number_format((int) $value) ?></div></div>
            </div></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between"><span>Fraud rule performance</span><a class="small text-pg" href="<?= e(url('admin/rules.php')) ?>">Manage rules</a></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Code</th><th>Rule</th><th class="text-center">Weight</th><th class="text-center">Fired</th><th class="text-center">Confirmed</th><th class="text-center">Dismissed</th><th class="text-center">Precision</th></tr></thead>
                    <tbody>
                    <?php foreach ($ruleStats as $r):
                        $reviewed = (int) $r['confirmed'] + (int) $r['dismissed']; ?>
                        <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
                            <td class="fw-semibold"><?= e($r['rule_code']) ?></td>
                            <td class="small"><?= e($r['rule_name']) ?><?= $r['is_active'] ? '' : ' <span class="badge bg-secondary">off</span>' ?></td>
                            <td class="text-center"><?= (int) $r['weight'] ?></td>
                            <td class="text-center"><?= (int) $r['fired'] ?></td>
                            <td class="text-center"><?= (int) $r['confirmed'] ?></td>
                            <td class="text-center"><?= (int) $r['dismissed'] ?></td>
                            <td class="text-center"><?= $reviewed ? round($r['confirmed'] / $reviewed * 100) . '%' : '&ndash;' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between"><span>Recent activity</span><a class="small text-pg" href="<?= e(url('admin/logs.php')) ?>">Full log</a></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($recent as $l): ?>
                    <li class="list-group-item small">
                        <div class="d-flex justify-content-between"><strong><?= e($l['full_name'] ?? 'Unknown') ?></strong><span class="text-muted"><?= e(date('d M H:i', strtotime($l['created_at']))) ?></span></div>
                        <?= e($l['action']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
