<?php
/** Downloads a report as PDF (Dompdf) or CSV. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/report_data.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$user = require_login();
$titles = [
    'summary'      => 'Monthly Spending Summary',
    'flagged'      => 'Flagged Transactions (Fraud Report)',
    'recipients'   => 'Top Recipients',
    'charges'      => 'Transaction Charges',
    'transactions' => 'Transaction List',
];
$type = isset($titles[$_GET['type'] ?? '']) ? $_GET['type'] : 'summary';
$format = ($_GET['format'] ?? 'pdf') === 'csv' ? 'csv' : 'pdf';
$sid = (int) ($_GET['statement'] ?? 0) ?: null;

if ($sid) {
    $owned = array_map('intval', array_column(user_statements($user), 'statement_id'));
    if (!in_array($sid, $owned, true)) {
        http_response_code(404);
        exit('Statement not found.');
    }
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null;
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : null;
$rs = new ReportService(db(), $user, $sid, $from, $to);
$period = ($from || $to) ? 'Period: ' . ($from ? date('d M Y', strtotime($from)) : 'start') . ' to ' . ($to ? date('d M Y', strtotime($to)) : 'today') : 'All dates';
$sections = build_report($type, $rs, db());
$filename = 'pesaguard_' . $type . ($sid ? "_statement$sid" : '') . '_' . date('Ymd_His');
log_activity((int) $user['user_id'], "Exported {$titles[$type]} report as " . strtoupper($format));

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                                         // UTF-8 BOM so Excel opens it correctly
    fputcsv($out, [APP_NAME . ' - ' . $titles[$type]], ',', '"', '\\');
    fputcsv($out, ['Generated for ' . $user['full_name'] . ' on ' . date('d M Y H:i') . ' - ' . $period], ',', '"', '\\');
    foreach ($sections as [$heading, $headers, $rows]) {
        fputcsv($out, [], ',', '"', '\\');
        fputcsv($out, [$heading], ',', '"', '\\');
        fputcsv($out, $headers, ',', '"', '\\');
        foreach ($rows as $r) {
            // Remove thousands separators so spreadsheets treat amounts as numbers
            fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^-?[\d,]+\.\d{2}$/', $v) ? str_replace(',', '', $v) : $v, $r), ',', '"', '\\');
        }
    }
    fclose($out);
    exit;
}

// ---------- PDF ----------
ob_start();
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 22mm 14mm 18mm 14mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1d2733; }
    header { position: fixed; top: -16mm; left: 0; right: 0; border-bottom: 2px solid #0b7a3e; padding-bottom: 4px; }
    header .brand { color: #0b7a3e; font-weight: bold; font-size: 13pt; }
    header .tag { color: #6b7785; font-size: 8pt; }
    footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #6b7785; border-top: 1px solid #dfe3e8; padding-top: 3px; }
    h1 { font-size: 14pt; margin: 0 0 2px 0; }
    .meta { color: #6b7785; margin-bottom: 10px; }
    h2 { font-size: 10.5pt; color: #0b7a3e; margin: 14px 0 6px 0; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #0b7a3e; color: #fff; text-align: left; padding: 4px 5px; font-size: 8pt; }
    td { padding: 3px 5px; border-bottom: 1px solid #e5e9ee; font-size: 8pt; vertical-align: top; }
    tr:nth-child(even) td { background: #f6f8fa; }
    .num { text-align: right; white-space: nowrap; }
    .empty { text-align: center; color: #6b7785; padding: 10px; }
    .pagenum:before { content: counter(page); }
</style></head>
<body>
<header><span class="brand"><?= e(APP_NAME) ?></span> &nbsp; <span class="tag"><?= e(APP_TAGLINE) ?></span></header>
<footer>Generated on <?= e(date('d M Y H:i')) ?> for <?= e($user['full_name']) ?> &middot; Confidential: contains personal financial data &middot; Page <span class="pagenum"></span></footer>
<h1><?= e($titles[$type]) ?></h1>
<div class="meta"><?= $sid ? 'Statement #' . $sid : 'All statements' ?> &middot; <?= e($period) ?></div>
<?php foreach ($sections as [$heading, $headers, $rows, $numeric]): ?>
    <h2><?= e($heading) ?></h2>
    <table>
        <thead><tr><?php foreach ($headers as $i => $h): ?><th class="<?= in_array($i, $numeric, true) ? 'num' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><?php foreach (array_values($r) as $i => $c): ?><td class="<?= in_array($i, $numeric, true) ? 'num' : '' ?>"><?= e((string) $c) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td class="empty" colspan="<?= count($headers) ?>">No data for this report.</td></tr><?php endif; ?>
        </tbody>
    </table>
<?php endforeach; ?>
</body></html>
<?php
$html = ob_get_clean();
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->setChroot(APP_ROOT);
$options->set('tempDir', UPLOAD_PATH);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', $type === 'transactions' || $type === 'flagged' ? 'landscape' : 'portrait');
$dompdf->render();
$dompdf->stream($filename . '.pdf', ['Attachment' => true]);
