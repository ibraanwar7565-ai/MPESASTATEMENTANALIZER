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
    'charges'      => ['Transaction charges', 'M-Pesa charges paid per month.', 'receipt'],
    'transactions' => ['Full transaction list', 'Every transaction with category and risk score.', 'list-ul'],
];
$type = isset($types[$type]) ? $type : 'summary';
$rs = new ReportService(db(), $user, $sid ?: null);

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
                <a href="?type=<?= e($key) ?>&statement=<?= $sid ?>" class="list-group-item list-group-item-action <?= $type === $key ? 'active bg-success border-success' : '' ?>">
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
                    <form method="get" class="d-flex">
                        <input type="hidden" name="type" value="<?= e($type) ?>">
                        <select name="statement" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Statement">
                            <option value="">All statements</option>
                            <?php foreach ($statements as $s): ?>
                                <option value="<?= (int) $s['statement_id'] ?>" <?= $sid === (int) $s['statement_id'] ? 'selected' : '' ?>>#<?= (int) $s['statement_id'] ?> &middot; <?= e($s['file_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a class="btn btn-sm btn-danger text-nowrap" href="<?= e(url("reports/export.php?type=$type&format=pdf&statement=$sid")) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
                    <a class="btn btn-sm btn-success text-nowrap" href="<?= e(url("reports/export.php?type=$type&format=csv&statement=$sid")) ?>"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
                </div>
            </div>
            <div class="card-body">
                <?php require __DIR__ . '/report_body.php'; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
