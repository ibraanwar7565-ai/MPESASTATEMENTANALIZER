<?php
require_once __DIR__ . '/../config/config.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $importer = new StatementImporter(db());
    try {
        if (isset($_POST['sample'])) {
            // Demo data: copy a bundled sample statement into the temporary folder and import it
            $samples = [
                'csv' => ['sample_statement.csv', ''],
                'pdf' => ['sample_statement.pdf', '123456'],
            ];
            [$file, $pwd] = $samples[$_POST['sample']] ?? $samples['csv'];
            $tmp = UPLOAD_PATH . uniqid('sample_', true) . '.' . pathinfo($file, PATHINFO_EXTENSION);
            copy(APP_ROOT . '/sample/' . $file, $tmp);
            $result = $importer->import((int) $user['user_id'], $tmp, $file, $pwd);
        } else {
            $f = $_FILES['statement'] ?? null;
            if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Choose a statement file to upload.');
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE
                    ? 'The file is larger than the allowed size (10 MB).' : 'The upload failed. Please try again.');
            }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'csv'], true)) {
                throw new RuntimeException('Only PDF or CSV M-Pesa statements are allowed.');
            }
            $password = (string) ($_POST['password'] ?? '');
            if ($ext === 'pdf' && $password === '') {
                throw new RuntimeException('Enter the statement password that Safaricom sent to you by SMS.');
            }
            $tmp = UPLOAD_PATH . bin2hex(random_bytes(8)) . '.' . $ext;      // random name, never the user's
            if (!move_uploaded_file($f['tmp_name'], $tmp)) {
                throw new RuntimeException('The file could not be saved for processing.');
            }
            $result = $importer->import((int) $user['user_id'], $tmp, $f['name'], $password);
        }
        flash('success', "Statement analysed: {$result['total']} transactions imported, {$result['flagged']} flagged as suspicious. The uploaded file has been deleted.");
        redirect('dashboard.php?statement=' . $result['statement_id']);
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
        redirect('statements/upload.php');
    }
}

$pageTitle = 'Upload statement';
$active = 'statements';
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 page-title">Upload M-Pesa statement</h1>
<p class="page-sub">Your statement is analysed immediately and the uploaded file is deleted after processing.</p>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body p-4">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_SIZE ?>">
                    <div class="drop-zone mb-3">
                        <i class="bi bi-file-earmark-pdf"></i>
                        <p class="mb-2 mt-2 fw-semibold">Choose your M-Pesa statement (PDF or CSV, up to 10 MB)</p>
                        <input class="form-control" type="file" id="statement_file" name="statement" accept=".pdf,.csv" required>
                    </div>
                    <div class="mb-3" id="pdf-password-group">
                        <label class="form-label" for="password">Statement password</label>
                        <input type="password" class="form-control" id="password" name="password" autocomplete="off" placeholder="Code sent to you by SMS">
                        <div class="form-text">The password is used once to open the PDF and is never stored.</div>
                    </div>
                    <button class="btn btn-pg"><i class="bi bi-cloud-upload me-1"></i>Upload and analyse</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">How to get your statement</div>
            <div class="card-body small">
                <ol class="mb-0 ps-3">
                    <li>Open the M-PESA App or MySafaricom App, or dial <strong>*334#</strong>.</li>
                    <li>Choose <em>Statement</em>, then <em>Request full statement</em>, and pick the period.</li>
                    <li>Safaricom e-mails you the PDF and sends the password by SMS.</li>
                    <li>Upload the PDF here and enter that password.</li>
                </ol>
            </div>
        </div>
        <div class="card">
            <div class="card-header">Try it with sample data</div>
            <div class="card-body">
                <p class="small text-muted">The sample statement (39 transactions, October 2026) has planted fraud patterns: a very large transfer, late-night bursts, a blacklisted number, a reversal scam and a loan-then-withdraw.</p>
                <form method="post" class="d-flex flex-wrap gap-2">
                    <?= csrf_field() ?>
                    <button name="sample" value="csv" class="btn btn-outline-success btn-sm"><i class="bi bi-filetype-csv me-1"></i>Load sample CSV</button>
                    <button name="sample" value="pdf" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-lock me-1"></i>Load sample PDF (password 123456)</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
