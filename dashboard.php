<?php
require_once __DIR__ . '/config/config.php';
$user = require_login();

$statements = user_statements($user);
$sid = (int) ($_GET['statement'] ?? 0) ?: null;
if ($sid && !in_array($sid, array_map('intval', array_column($statements, 'statement_id')), true)) {
    $sid = null;
}

$rs = new ReportService(db(), $user, $sid);
$sum = $rs->summary();
$monthly = $rs->monthly();
$cats = $rs->byCategory('OUT');
$top = array_slice($rs->topRecipients(6), 0, 6);
$risk = $rs->riskDistribution();
$hourly = $rs->hourly();
$alerts = array_slice($rs->flagged('Pending'), 0, 6);
$ruleNames = ReportService::ruleNames(db());

$pageTitle = 'Dashboard';
$active = 'dashboard';
$useCharts = true;
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div>
        <h1 class="h3 page-title">Dashboard</h1>
        <p class="page-sub">
            <?php if ($sum['first_tx']): ?>
                Transactions from <?= e(date('j M Y', strtotime($sum['first_tx']))) ?> to <?= e(date('j M Y', strtotime($sum['last_tx']))) ?>
            <?php else: ?>
                Upload an M-Pesa statement to see your analysis.
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2 no-print">
        <?php if ($statements): ?>
        <form method="get" class="d-flex">
            <select name="statement" class="form-select" onchange="this.form.submit()" aria-label="Choose statement">
                <option value="">All statements</option>
                <?php foreach ($statements as $s): ?>
                    <option value="<?= (int) $s['statement_id'] ?>" <?= $sid === (int) $s['statement_id'] ? 'selected' : '' ?>>
                        #<?= (int) $s['statement_id'] ?> &middot; <?= e($s['file_name']) ?><?= $user['role'] === 'Admin' ? ' (' . e($s['full_name']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
        <a href="<?= e(url('statements/upload.php')) ?>" class="btn btn-pg text-nowrap"><i class="bi bi-cloud-upload me-1"></i>Upload statement</a>
    </div>
</div>

<?php if (!$statements): ?>
    <div class="card p-5 text-center">
        <div class="display-4 text-pg mb-2"><i class="bi bi-file-earmark-arrow-up"></i></div>
        <h2 class="h5">No statements yet</h2>
        <p class="text-muted">Upload your M-Pesa statement (PDF with its password, or CSV), or load the sample statement to try the system.</p>
        <div><a href="<?= e(url('statements/upload.php')) ?>" class="btn btn-pg">Upload a statement</a></div>
    </div>
<?php else: ?>

<div class="row g-3 mb-3">
    <?php
    $kpis = [
        ['Money in', money($sum['total_in']), 'arrow-down-left-circle', 'bg-pg-soft text-pg'],
        ['Money out', money($sum['total_out']), 'arrow-up-right-circle', 'bg-danger-subtle text-danger'],
        ['Transaction charges', money($sum['charges']), 'receipt', 'bg-warning-subtle text-warning-emphasis'],
        ['Closing balance', money($sum['closing_balance']), 'wallet2', 'bg-primary-subtle text-primary'],
        ['Transactions', number_format($sum['tx_count']), 'list-ol', 'bg-secondary-subtle text-secondary'],
        ['Flagged (pending review)', (int) $sum['flagged'] . ' (' . (int) $sum['pending'] . ')', 'exclamation-triangle', 'bg-danger-subtle text-danger'],
    ];
    foreach ($kpis as [$label, $value, $icon, $cls]): ?>
    <div class="col-sm-6 col-lg-4 col-xxl-2">
        <div class="card kpi h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="kpi-icon <?= $cls ?>"><i class="bi bi-<?= $icon ?>"></i></div>
                <div class="min-w-0">
                    <div class="kpi-label"><?= e($label) ?></div>
                    <div class="kpi-value text-truncate"><?= e($value) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">Monthly cash flow</div>
            <div class="card-body"><div class="chart-box"><canvas id="cashFlowChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Spending by category</div>
            <div class="card-body"><div class="chart-box"><canvas id="categoryChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-exclamation-triangle text-danger me-1"></i>Fraud alerts awaiting review</span>
                <a href="<?= e(url('flagged.php')) ?>" class="small text-pg">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Date</th><th>Details</th><th class="text-end">Amount</th><th>Reasons</th><th>Risk</th></tr></thead>
                    <tbody>
                    <?php foreach ($alerts as $a): ?>
                        <tr class="<?= $a['risk_level'] === 'High' ? 'row-high' : 'row-medium' ?>">
                            <td class="text-nowrap small"><?= e(date('d M H:i', strtotime($a['completion_time']))) ?></td>
                            <td class="small"><?= e($a['details']) ?></td>
                            <td class="text-end amount-out text-nowrap"><?= e(money($a['amount'])) ?></td>
                            <td><?php foreach (explode(',', $a['rule_codes']) as $c): ?><span class="rule-chip" title="<?= e($ruleNames[$c] ?? '') ?>"><?= e($c) ?></span><?php endforeach; ?></td>
                            <td><?= risk_badge($a['risk_level']) ?> <span class="small text-muted"><?= (int) $a['risk_score'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$alerts): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-check-circle text-pg me-1"></i>No alerts waiting for review.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Risk level of outgoing transactions</div>
            <div class="card-body"><div class="chart-box" style="height:250px"><canvas id="riskChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Top recipients</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Recipient</th><th class="text-center">Times</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($top as $r): ?>
                        <tr>
                            <td class="small"><?= e($r['details']) ?></td>
                            <td class="text-center"><?= (int) $r['tx_count'] ?></td>
                            <td class="text-end text-nowrap"><?= e(money($r['total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Outgoing transactions by hour of day</div>
            <div class="card-body"><div class="chart-box" style="height:250px"><canvas id="hourChart"></canvas></div></div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
$chartData = [
    'months'   => array_map(fn($m) => date('M Y', strtotime($m['month'] . '-01')), $monthly),
    'in'       => array_map(fn($m) => round((float) $m['money_in'], 2), $monthly),
    'out'      => array_map(fn($m) => round((float) $m['money_out'], 2), $monthly),
    'catLabels'=> array_column($cats, 'category'),
    'catTotals'=> array_map(fn($c) => round((float) $c['total'], 2), $cats),
    'risk'     => array_values($risk),
    'hourly'   => $hourly,
];
$pageScript = '<script>window.PG_DASH = ' . json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'
            . '<script src="' . e(url('assets/js/dashboard.js')) . '"></script>';
require APP_ROOT . '/includes/footer.php';
