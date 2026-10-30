<?php
require_once __DIR__ . '/../config/config.php';
$user = require_login();

$statements = user_statements($user);
$sid = (int) ($_GET['statement'] ?? 0);
$type = $_GET['type'] ?? 'summary';
$types = [
    'summary'      => ['Monthly spending summary', 'Money in and out per month and spending by category.', 'calendar3'],
    'flagged'      => ['Flagged transactions (fraud report)', 'Suspicious transactions, the rules they triggered and the review status.', 'exclamation-triangle'],
    'recipients'   => ['Top recipients', 'Where most of your money goes.', 'people'],
    'charges'      => ['Transaction charges', 'M-Pesa charges paid per transaction type.', 'receipt'],
    'transactions' => ['Full transaction list', 'Every transaction with category and risk score.', 'list-ul'],
];
$type = isset($types[$type]) ? $type : 'summary';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$rs = new ReportService(db(), $user, $sid ?: null, $from, $to);
$qs = http_build_query(['statement' => $sid, 'from' => $from, 'to' => $to]);

$pageTitle = 'Reports';
$active = 'reports';
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 page-title">Reports</h1>
<p class="page-sub">Preview a report, then download it as PDF or CSV.</p>

<div class="row g-3">
    <div class="col-lg-3">
        <div class="list-group mb-3">
            <?php foreach ($types as $key => [$title, $desc, $icon]): ?>
                <a href="?type=<?= e($key) ?>&<?= e($qs) ?>" class="list-group-item list-group-item-action <?= $type === $key ? 'active bg-success border-success' : '' ?>">
                    <div class="fw-semibold"><i class="bi bi-<?= $icon ?> me-1"></i><?= e($title) ?></div>
                    <div class="small <?= $type === $key ? 'text-white-50' : 'text-muted' ?>"><?= e($desc) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-lg-9">
        <div class="card">
            <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <span><?= e($types[$type][0]) ?></span>
                <div class="d-flex gap-2 no-print">
                    <form method="get" class="d-flex flex-wrap gap-1">
                        <input type="hidden" name="type" value="<?= e($type) ?>">
                        <input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm" style="width:auto" aria-label="Period from" title="Period from">
                        <input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm" style="width:auto" aria-label="Period to" title="Period to">
                        <select name="statement" class="form-select form-select-sm" style="width:auto" aria-label="Statement">
                            <option value="">All statements</option>
                            <?php foreach ($statements as $s): ?>
                                <option value="<?= (int) $s['statement_id'] ?>" <?= $sid === (int) $s['statement_id'] ? 'selected' : '' ?>>#<?= (int) $s['statement_id'] ?> &middot; <?= e($s['file_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary">Apply</button>
                    </form>
                    <a class="btn btn-sm btn-danger text-nowrap" href="<?= e(url("reports/export.php?type=$type&format=pdf&$qs")) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
                    <a class="btn btn-sm btn-success text-nowrap" href="<?= e(url("reports/export.php?type=$type&format=csv&$qs")) ?>"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
                </div>
            </div>
            <div class="card-body">
                <?php require __DIR__ . '/report_body.php'; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
