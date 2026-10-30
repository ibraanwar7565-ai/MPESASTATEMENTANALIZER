<?php
require_once __DIR__ . '/config/config.php';
$user = require_login();

// Review a flagged transaction: Confirmed (fraud) / Dismissed (false alarm) / Pending (undo)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $txId = (int) ($_POST['transaction_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    if (in_array($decision, ['Confirmed', 'Dismissed', 'Pending'], true)) {
        $sql = 'UPDATE flagged_transactions SET review_status = ?, reviewed_by = ?, reviewed_at = ? WHERE transaction_id = ?'
             . ($user['role'] === 'Admin' ? '' : ' AND transaction_id IN (SELECT t.transaction_id FROM transactions t
                     JOIN statements s ON s.statement_id = t.statement_id WHERE s.user_id = ?)');
        $args = [$decision, $decision === 'Pending' ? null : $user['user_id'], $decision === 'Pending' ? null : now(), $txId];
        if ($user['role'] !== 'Admin') {
            $args[] = $user['user_id'];
        }
        $st = db()->prepare($sql);
        $st->execute($args);
        if ($st->rowCount()) {
            log_activity((int) $user['user_id'], "Marked flagged transaction #$txId as $decision");
            $msg = ['Confirmed' => 'Marked as fraud. Call Safaricom customer care on 100 and forward any scam SMS to 333.',
                    'Dismissed' => 'Alert dismissed as a false alarm.',
                    'Pending'   => 'Alert moved back to pending review.'][$decision];
            flash($decision === 'Confirmed' ? 'warning' : 'success', $msg);
        }
    }
    redirect('flagged.php?' . http_build_query(['status' => $_POST['status_filter'] ?? '', 'level' => $_POST['level_filter'] ?? '']));
}

$status = in_array($_GET['status'] ?? 'Pending', ['Pending', 'Confirmed', 'Dismissed', ''], true) ? ($_GET['status'] ?? 'Pending') : 'Pending';
$level = in_array($_GET['level'] ?? '', ['Medium', 'High'], true) ? $_GET['level'] : '';
$rs = new ReportService(db(), $user);
$rows = $rs->flagged($status, $level);
$ruleNames = ReportService::ruleNames(db());
$counts = [];
foreach (['Pending', 'Confirmed', 'Dismissed', ''] as $s) {
    $counts[$s] = count($rs->flagged($s));
}

$pageTitle = 'Fraud alerts';
$active = 'flagged';
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div>
        <h1 class="h3 page-title">Fraud alerts</h1>
        <p class="page-sub">Outgoing transactions with a risk score of <?= MEDIUM_RISK ?> or more. Confirm the ones that were fraud and dismiss false alarms.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('reports/export.php?type=flagged&format=pdf')) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Fraud report (PDF)</a>
</div>

<ul class="nav nav-pills mb-3 no-print">
    <?php foreach (['Pending' => 'Pending review', 'Confirmed' => 'Confirmed fraud', 'Dismissed' => 'Dismissed', '' => 'All'] as $s => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $status === $s ? 'active bg-success' : 'text-dark' ?>" href="?status=<?= e($s) ?>&level=<?= e($level) ?>"><?= e($label) ?> <span class="badge bg-light text-dark ms-1"><?= $counts[$s] ?></span></a></li>
    <?php endforeach; ?>
    <li class="nav-item ms-auto">
        <select class="form-select form-select-sm" onchange="location='?status=<?= e($status) ?>&level='+this.value" aria-label="Risk level">
            <option value="">Medium and High</option>
            <option value="High" <?= $level === 'High' ? 'selected' : '' ?>>High only</option>
            <option value="Medium" <?= $level === 'Medium' ? 'selected' : '' ?>>Medium only</option>
        </select>
    </li>
</ul>

<div class="row g-3">
<?php foreach ($rows as $r): ?>
    <div class="col-xl-6">
        <div class="card h-100 border-start border-4 <?= $r['risk_level'] === 'High' ? 'border-danger' : 'border-warning' ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="fw-semibold"><?= e($r['details']) ?></div>
                        <div class="small text-muted">
                            <span class="font-monospace"><?= e($r['receipt_no']) ?></span> &middot; <?= e(date('D d M Y, H:i', strtotime($r['completion_time']))) ?>
                            <?php if ($user['role'] === 'Admin'): ?> &middot; <?= e($r['full_name']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="amount-out fs-5 text-nowrap"><?= e(money($r['amount'])) ?></div>
                        <?= risk_badge($r['risk_level']) ?> <span class="small">score <strong><?= (int) $r['risk_score'] ?></strong>/100</span>
                    </div>
                </div>
                <div class="small mb-3">
                    <div class="text-muted mb-1">Why it was flagged:</div>
                    <ul class="mb-0 ps-3">
                        <?php foreach (explode(',', $r['rule_codes']) as $c): ?>
                            <li><strong><?= e($c) ?></strong> &ndash; <?= e($ruleNames[$c] ?? $c) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div><?= status_badge($r['review_status']) ?>
                        <?php if ($r['reviewed_at']): ?><span class="small text-muted ms-1"><?= e(date('d M Y H:i', strtotime($r['reviewed_at']))) ?></span><?php endif; ?>
                    </div>
                    <form method="post" class="d-flex gap-1 no-print">
                        <?= csrf_field() ?>
                        <input type="hidden" name="transaction_id" value="<?= (int) $r['transaction_id'] ?>">
                        <input type="hidden" name="status_filter" value="<?= e($status) ?>">
                        <input type="hidden" name="level_filter" value="<?= e($level) ?>">
                        <?php if ($r['review_status'] !== 'Confirmed'): ?>
                            <button name="decision" value="Confirmed" class="btn btn-sm btn-danger"><i class="bi bi-shield-x me-1"></i>Confirm fraud</button>
                        <?php endif; ?>
                        <?php if ($r['review_status'] !== 'Dismissed'): ?>
                            <button name="decision" value="Dismissed" class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2 me-1"></i>Dismiss</button>
                        <?php endif; ?>
                        <?php if ($r['review_status'] !== 'Pending'): ?>
                            <button name="decision" value="Pending" class="btn btn-sm btn-link text-muted">Undo</button>
                        <?php endif; ?>
                        <?php if ($user['role'] === 'Admin' && $r['counterparty']): ?>
                            <a class="btn btn-sm btn-outline-dark" title="Add the recipient to the blacklist" href="<?= e(url('admin/blacklist.php?identifier=' . urlencode($r['counterparty']))) ?>"><i class="bi bi-slash-circle"></i></a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$rows): ?>
    <div class="col-12"><div class="card p-5 text-center text-muted"><div class="display-6 text-pg"><i class="bi bi-shield-check"></i></div>No alerts in this list.</div></div>
<?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
