<?php
/**
 * System status (admin only): 21 live checks that the database, the PHP backend,
 * the upload pipeline and the frontend files are all working and connected.
 */
require_once __DIR__ . '/../config/config.php';
// Admins only
$admin = require_admin();

// Each check proves that one part of the system can talk to the next one.
$checks = [];
// Record one check result
$add = function (string $group, string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = compact('group', 'name', 'ok', 'detail');
};

$t0 = microtime(true);
try {
    $version = db()->query(DB_DRIVER === 'sqlite' ? 'SELECT sqlite_version()' : 'SELECT VERSION()')->fetchColumn();
    $add('Database', 'Connection', true, (DB_DRIVER === 'sqlite' ? 'SQLite ' : 'MySQL ') . $version . ' answered in ' . round((microtime(true) - $t0) * 1000) . ' ms');
    $expected = ['users', 'statements', 'transactions', 'categories', 'fraud_rules', 'flagged_transactions', 'blacklist', 'activity_log'];
    $counts = [];
    $missing = [];
    foreach ($expected as $table) {
        try {
            $counts[] = $table . ' ' . (int) db()->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        } catch (PDOException $ex) {
            $missing[] = $table;
        }
    }
    $add('Database', 'Tables (8)', !$missing, $missing ? 'Missing: ' . implode(', ', $missing) : implode(' · ', $counts));
    $rules = (int) db()->query('SELECT COUNT(*) FROM fraud_rules WHERE is_active = 1')->fetchColumn();
    $add('Database', 'Fraud rules loaded', $rules > 0, $rules . ' active rule(s)');
} catch (PDOException $ex) {
    $add('Database', 'Connection', false, 'MySQL is not reachable. Start it in Laragon.');
}

$add('Backend', 'PHP version', version_compare(PHP_VERSION, '8.1', '>='), 'PHP ' . PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'gd', 'dom'] as $ext) {
    $add('Backend', "Extension $ext", extension_loaded($ext), extension_loaded($ext) ? 'Loaded' : 'Not loaded: enable it in Laragon > PHP > Extensions');
}
$add('Backend', 'PDF reader (PdfParser)', class_exists(\Smalot\PdfParser\Parser::class), 'vendor/smalot/pdfparser');
$add('Backend', 'PDF reports (Dompdf)', class_exists(\Dompdf\Dompdf::class), 'vendor/dompdf/dompdf');
$qpdf = qpdf_bin();
$add('Backend', 'qpdf (opens password-protected PDFs)', is_file($qpdf), $qpdf);
$add('Backend', 'Upload folder writable', is_writable(UPLOAD_PATH), UPLOAD_PATH);
$add('Backend', 'Session', session_status() === PHP_SESSION_ACTIVE, 'Logged in as ' . $admin['email']);

try {
    $parser = new StatementParser();
    $rows = $parser->parseCsv(APP_ROOT . '/sample/sample_statement.csv');
    $scored = (new FraudEngine(db()))->score($rows);
    $flagged = count(array_filter($scored, fn($t) => $t['risk_score'] >= MEDIUM_RISK));
    $add('Pipeline', 'Parser → Fraud engine → Database rules', count($rows) === 39, count($rows) . ' sample transactions read, ' . $flagged . ' flagged in ' . round((microtime(true) - $t0) * 1000) . ' ms');
} catch (Throwable $ex) {
    $add('Pipeline', 'Parser → Fraud engine → Database rules', false, $ex->getMessage());
}

foreach (['assets/vendor/bootstrap/bootstrap.min.css', 'assets/vendor/bootstrap/bootstrap.bundle.min.js', 'assets/vendor/chartjs/chart.umd.js', 'assets/css/style.css', 'assets/js/app.js', 'assets/js/dashboard.js'] as $asset) {
    $add('Frontend', basename($asset), is_file(APP_ROOT . '/' . $asset), $asset);
}

$failed = count(array_filter($checks, fn($c) => !$c['ok']));
$pageTitle = 'System status';
$active = 'admin';
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 page-title">System status</h1>
<p class="page-sub">Live check that the frontend, the PHP backend and the MySQL database are communicating.</p>
<div class="alert alert-<?= $failed ? 'danger' : 'success' ?>">
    <i class="bi bi-<?= $failed ? 'x-circle' : 'check-circle' ?> me-1"></i>
    <?= $failed ? e($failed . ' check(s) failed. See the rows marked in red.') : 'All ' . count($checks) . ' checks passed. Every part of the system is communicating.' ?>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th style="width:44px"></th><th>Layer</th><th>Check</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($checks as $c): ?>
                <tr class="<?= $c['ok'] ? '' : 'row-high' ?>">
                    <td><span class="status-dot <?= $c['ok'] ? 'ok' : 'bad' ?>" title="<?= $c['ok'] ? 'OK' : 'Failed' ?>"></span></td>
                    <td class="fw-semibold"><?= e($c['group']) ?></td>
                    <td><?= e($c['name']) ?></td>
                    <td class="small text-muted"><?= e($c['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small text-muted mt-3 mb-0"><span id="js-check"><span class="status-dot bad me-2"></span>Browser scripts not running.</span></p>
<?php
$pageScript = '<script>document.getElementById("js-check").innerHTML = \'<span class="status-dot ok me-2"></span>Browser scripts running: Bootstrap \' + (window.bootstrap ? "loaded" : "missing") + ".";</script>';
require APP_ROOT . '/includes/footer.php';
