<?php
require_once __DIR__ . '/../config/config.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $st = db()->prepare('UPDATE fraud_rules SET rule_name = ?, threshold = ?, weight = ?, is_active = ? WHERE rule_id = ?');
    $errors = 0;
    foreach ((array) ($_POST['rules'] ?? []) as $id => $r) {
        $name = trim($r['rule_name'] ?? '');
        $threshold = filter_var($r['threshold'] ?? '', FILTER_VALIDATE_FLOAT);
        $weight = filter_var($r['weight'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        if ($name === '' || $threshold === false || $threshold < 0 || $weight === false) {
            $errors++;
            continue;
        }
        $st->execute([mb_substr($name, 0, 100), $threshold, $weight, isset($r['is_active']) ? 1 : 0, (int) $id]);
    }
    log_activity((int) $admin['user_id'], 'Updated fraud rules');
    if ($errors) {
        flash('warning', "$errors rule(s) were not saved: the weight must be 0-100 and the threshold a positive number.");
    } else {
        flash('success', 'Fraud rules saved. Use "Re-score all statements" on the Admin overview to apply them to existing statements.');
    }
    if (isset($_POST['rescore'])) {
        $r = (new StatementImporter(db()))->rescore();
        flash('success', "Re-scored {$r['statements']} statement(s): {$r['flagged']} transaction(s) now flagged.");
    }
    redirect('admin/rules.php');
}

$rules = db()->query('SELECT * FROM fraud_rules ORDER BY rule_code')->fetchAll();
$help = [
    'R1' => 'Number of standard deviations above the average outgoing amount.',
    'R2' => 'Hour of the day; transfers before this hour are flagged.',
    'R3' => 'Number of outgoing transfers within 10 minutes.',
    'R4' => 'Minimum amount (KES) for a first payment to a new recipient.',
    'R5' => 'Not used; the blacklist decides.',
    'R6' => 'Minutes between "Funds received" and a similar (±5%) outgoing transfer.',
    'R7' => 'Percentage of the balance lost within one hour.',
    'R8' => 'Minutes between a Fuliza / M-Shwari loan and an outflow of 90% or more of it.',
];

$pageTitle = 'Fraud rules';
$active = 'admin';
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 page-title">Fraud detection rules</h1>
<p class="page-sub">Risk score = sum of the weights of the triggered rules (maximum 100). Low &lt; <?= MEDIUM_RISK ?>, Medium <?= MEDIUM_RISK ?>&ndash;<?= HIGH_RISK - 1 ?>, High &ge; <?= HIGH_RISK ?>.</p>
<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Code</th><th style="min-width:240px">Rule name</th><th style="width:140px">Threshold</th><th style="width:110px">Weight</th><th class="text-center">Active</th><th>Meaning of the threshold</th></tr></thead>
            <tbody>
            <?php foreach ($rules as $r): $id = (int) $r['rule_id']; ?>
                <tr>
                    <td class="fw-bold"><?= e($r['rule_code']) ?></td>
                    <td><input class="form-control form-control-sm" name="rules[<?= $id ?>][rule_name]" value="<?= e($r['rule_name']) ?>" maxlength="100" required></td>
                    <td><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="rules[<?= $id ?>][threshold]" value="<?= e(rtrim(rtrim(number_format((float) $r['threshold'], 2, '.', ''), '0'), '.')) ?>" required></td>
                    <td><input class="form-control form-control-sm" type="number" min="0" max="100" name="rules[<?= $id ?>][weight]" value="<?= (int) $r['weight'] ?>" required></td>
                    <td class="text-center"><div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" name="rules[<?= $id ?>][is_active]" <?= $r['is_active'] ? 'checked' : '' ?> aria-label="Active"></div></td>
                    <td class="small text-muted"><?= e($help[$r['rule_code']] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
        <button class="btn btn-pg"><i class="bi bi-save me-1"></i>Save rules</button>
        <button class="btn btn-outline-success" name="rescore" value="1"><i class="bi bi-arrow-repeat me-1"></i>Save and re-score all statements</button>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
