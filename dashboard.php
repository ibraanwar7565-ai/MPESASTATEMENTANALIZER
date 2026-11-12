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

<?php
$in = (float) $sum['total_in'];
$out = (float) $sum['total_out'];
$net = $in - $out;
$flowTotal = max($in + $out, 1);
$inPct = round($in / $flowTotal * 100, 1);
$outTx = max(array_sum($risk), 1);
$firstName = explode(' ', trim($user['full_name']))[0];
?>
<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <section class="hero h-100" aria-label="Statement summary">
            <p class="hero-hello">Habari, <?= e($firstName) ?>. Here is what your statement says.</p>
            <div class="hero-net">
                <span class="hero-net-label">Net movement</span>
                <span class="hero-net-value"><?= abs($net) < 0.005 ? '' : ($net < 0 ? '&minus;' : '+') ?><?= e(money(abs($net))) ?></span>
            </div>
            <div class="flow" role="img" aria-label="Money in <?= e(money($in)) ?>, money out <?= e(money($out)) ?>">
                <span class="flow-in" style="width: <?= $inPct ?>%"></span><span class="flow-out" style="width: <?= 100 - $inPct ?>%"></span>
            </div>
            <div class="hero-figures">
                <div><span class="dot dot-in"></span>Money in<strong><?= e(money($in)) ?></strong></div>
                <div><span class="dot dot-out"></span>Money out<strong><?= e(money($out)) ?></strong></div>
                <div>Transaction charges<strong><?= e(money($sum['charges'])) ?></strong></div>
                <div>Closing balance<strong><?= e(money($sum['closing_balance'])) ?></strong></div>
                <div>Transactions<strong><?= number_format($sum['tx_count']) ?></strong></div>
            </div>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="watch h-100 <?= (int) $sum['pending'] ? 'watch-alert' : 'watch-clear' ?>" aria-label="Fraud watch">
            <div class="watch-head">
                <div class="watch-count"><?= (int) $sum['pending'] ?></div>
                <div>
                    <div class="watch-title"><?= (int) $sum['pending'] === 1 ? 'alert needs your review' : 'alerts need your review' ?></div>
                    <div class="watch-sub">Flagged (pending review): <?= (int) $sum['flagged'] ?> (<?= (int) $sum['pending'] ?>)</div>
                </div>
            </div>
            <div class="riskbar" role="img" aria-label="Low <?= $risk['Low'] ?>, Medium <?= $risk['Medium'] ?>, High <?= $risk['High'] ?> outgoing transactions">
                <span class="rb-low" style="flex: <?= max($risk['Low'], 0.0001) ?>"></span><span class="rb-med" style="flex: <?= max($risk['Medium'], 0.0001) ?>"></span><span class="rb-high" style="flex: <?= max($risk['High'], 0.0001) ?>"></span>
            </div>
            <div class="risk-legend">
                <span><i class="rb-low"></i>Low <?= $risk['Low'] ?></span>
                <span><i class="rb-med"></i>Medium <?= $risk['Medium'] ?></span>
                <span><i class="rb-high"></i>High <?= $risk['High'] ?></span>
            </div>
            <a href="<?= e(url('flagged.php')) ?>" class="btn <?= (int) $sum['pending'] ? 'btn-alert' : 'btn-outline-success' ?> w-100 mt-auto"><?= (int) $sum['pending'] ? 'Review alerts' : 'View flagged transactions' ?></a>
        </section>
    </div>
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
                <span><i class="bi bi-exclamation-triangle text-danger me-1"></i>Latest alerts awaiting review</span>
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
            . '<script src="' . e(url('assets/js/dashboard.js')) . '?v=5"></script>';
require APP_ROOT . '/includes/footer.php';
