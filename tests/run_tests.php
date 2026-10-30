<?php
/**
 * Automated tests for the parser, categoriser and fraud engine.
 * Run from the project folder:   php tests/run_tests.php
 * Uses a temporary SQLite database, so MySQL does not need to be running.
 */
putenv('PG_DB_DRIVER=sqlite');
putenv('PG_SQLITE_PATH=' . sys_get_temp_dir() . '/pesaguard_test_' . getmypid() . '.sqlite');
require __DIR__ . '/../config/config.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $ok || $detail === '' ? '' : " -> $detail");
}

// ---------- Fresh database with the same seed data as MySQL ----------
@unlink(SQLITE_PATH);
db()->exec(file_get_contents(__DIR__ . '/sqlite_schema.sql'));
preg_match_all('/^INSERT INTO .*?;\s*$/ms', file_get_contents(APP_ROOT . '/database/mpesa_analyzer_db.sql'), $m);
foreach ($m[0] as $insert) {
    db()->exec($insert);
}

echo "\nUT-01..05  Statement parser\n";
$parser = new StatementParser();
$csvRows = $parser->parseCsv(APP_ROOT . '/sample/sample_statement.csv');
check('CSV: all 39 rows read', count($csvRows) === 39, (string) count($csvRows));
check('CSV: rows sorted oldest first', $csvRows[0]['receipt_no'] === 'TJ01T6EDV4' && end($csvRows)['receipt_no'] === 'TJ398NT486');
check('CSV: salary is IN 65,000.00', $csvRows[0]['direction'] === 'IN' && $csvRows[0]['amount'] == 65000.0);
check('CSV: counterparty extracted', $csvRows[18]['counterparty'] === '0722***418', $csvRows[18]['counterparty']);

$text = "TJ02U0YB5Y 2026-10-01 09:06:00 Airtime Purchase Completed -143.00 64,857.00\n"
      . "TJ03LH7DPU 2026-10-01 13:25:00 Merchant Payment to 5xxxx3 -\nNAIVAS SUPERMARKET Completed -597.00 64,260.00\n"
      . "TJ0FAILED1 2026-10-01 14:00:00 Customer Transfer to 0722***418 Failed -100.00 64,260.00\n";
$t = $parser->parseText($text);
check('Text: wrapped details joined, failed rows skipped', count($t) === 2 && $t[1]['details'] === 'Merchant Payment to 5xxxx3 - NAIVAS SUPERMARKET');
check('Text: withdrawn amount is OUT', $t[0]['direction'] === 'OUT' && $t[0]['amount'] == 143.0 && $t[0]['balance'] == 64857.0);

$pdfFile = APP_ROOT . '/storage/tmp/test_' . getmypid() . '.pdf';
copy(APP_ROOT . '/sample/sample_statement.pdf', $pdfFile);
try {
    $pdfRows = $parser->parsePdf($pdfFile, '123456');
    check('PDF: decrypted with password and 39 rows read', count($pdfRows) === 39, (string) count($pdfRows));
    check('PDF rows identical to CSV rows', $pdfRows == $csvRows);
} catch (Throwable $ex) {
    check('PDF: decrypted with password', false, $ex->getMessage());
}
try {
    $parser->parsePdf($pdfFile, 'wrong');
    check('PDF: wrong password rejected', false);
} catch (RuntimeException $ex) {
    check('PDF: wrong password rejected', str_contains($ex->getMessage(), 'Wrong statement password'));
}
@unlink($pdfFile);

echo "\nUT-06..08  Categoriser\n";
$cat = new Categorizer(db());
$names = db()->query('SELECT category_id, name FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
$cases = [
    'Pay Bill to 888880 - KPLC PREPAID Acc. 5512'             => 'Pay Bill',
    'Customer Withdrawal At Agent Till 301221 - JOY AGENCIES' => 'Withdrawals',
    'Merchant Payment to 5xxxx3 - NAIVAS SUPERMARKET'         => 'Buy Goods',
    'Customer Transfer of Funds Charge'                        => 'Charges',
    'OverDraft of Credit Party'                                => 'Loans & Fuliza',
    'Deposit of Funds at Agent Till 12345 - AGENT'             => 'Deposits',
];
foreach ($cases as $details => $expected) {
    $got = $names[$cat->categorize($details)] ?? 'none';
    check("\"$details\" -> $expected", $got === $expected, $got);
}

echo "\nUT-09..16  Fraud engine (planted patterns)\n";
$scored = (new FraudEngine(db()))->score($csvRows);
$by = array_column($scored, null, 'receipt_no');
$expected = [
    'TJ296YKJBA' => [['R1'], 30, 'Medium'],        // very large transfer
    'TJ30G9J3NP' => [['R2'], 15, 'Low'],           // late night
    'TJ31BSPU8R' => [['R2'], 15, 'Low'],
    'TJ32WS2JDY' => [['R2', 'R3'], 40, 'Medium'],  // third transfer in 10 minutes
    'TJ335928JK' => [['R5'], 40, 'Medium'],        // blacklisted number
    'TJ35KMK6HD' => [['R6'], 35, 'Medium'],        // reversal scam
    'TJ36W996GD' => [[], 0, 'Low'],                // normal bill
    'TJ398NT486' => [['R7', 'R8'], 45, 'Medium'],  // Fuliza then withdraw everything
];
foreach ($expected as $receipt => [$rules, $score, $level]) {
    $t = $by[$receipt];
    check("$receipt -> " . ($rules ? implode('+', $rules) : 'no rule') . " = $score $level",
          $t['rules'] === $rules && $t['risk_score'] === $score && $t['risk_level'] === $level,
          implode('+', $t['rules']) . " = {$t['risk_score']} {$t['risk_level']}");
}
$flagged = count(array_filter($scored, fn($t) => $t['risk_score'] >= MEDIUM_RISK));
check('Exactly 5 transactions flagged (score >= 30)', $flagged === 5, (string) $flagged);
check('Incoming transactions are never scored', !array_filter($scored, fn($t) => $t['direction'] === 'IN' && $t['risk_score'] > 0));

$charge = [
    ['receipt_no' => 'A1', 'completion_time' => '2026-10-01 10:00:00', 'details' => 'Customer Transfer to 0711***111 - X', 'direction' => 'OUT', 'amount' => 500.0, 'balance' => 9500.0, 'counterparty' => '0711***111'],
    ['receipt_no' => 'A1', 'completion_time' => '2026-10-01 10:00:00', 'details' => 'Customer Transfer of Funds Charge', 'direction' => 'OUT', 'amount' => 7.0, 'balance' => 9493.0, 'counterparty' => ''],
    ['receipt_no' => 'A2', 'completion_time' => '2026-10-01 10:03:00', 'details' => 'Customer Transfer to 0711***111 - X', 'direction' => 'OUT', 'amount' => 500.0, 'balance' => 8993.0, 'counterparty' => '0711***111'],
    ['receipt_no' => 'A2', 'completion_time' => '2026-10-01 10:03:00', 'details' => 'Customer Transfer of Funds Charge', 'direction' => 'OUT', 'amount' => 7.0, 'balance' => 8986.0, 'counterparty' => ''],
];
$s = (new FraudEngine(db()))->score($charge);
check('Charges do not count towards rapid transfers (R3)', !in_array('R3', $s[2]['rules'], true) && $s[3]['risk_score'] === 0);

echo "\nIT-01..04  Import pipeline and database\n";
$tmp = APP_ROOT . '/storage/tmp/it_' . getmypid() . '.csv';
copy(APP_ROOT . '/sample/sample_statement.csv', $tmp);
$res = (new StatementImporter(db()))->import(2, $tmp, 'sample_statement.csv');
check('Import: 39 transactions saved, 5 flagged', $res['total'] === 39 && $res['flagged'] === 5, json_encode($res));
check('Import: uploaded file deleted after processing', !file_exists($tmp));
check('Import: one flag stored per triggered rule (7 rows)', (int) db()->query('SELECT COUNT(*) FROM flagged_transactions')->fetchColumn() === 7);
copy(APP_ROOT . '/sample/sample_statement.csv', $tmp);
try {
    (new StatementImporter(db()))->import(2, $tmp, 'sample_statement.csv');
    check('Import: duplicate statement rejected', false);
} catch (RuntimeException $ex) {
    check('Import: duplicate statement rejected', str_contains($ex->getMessage(), 'already uploaded'));
}
db()->exec("UPDATE fraud_rules SET weight = 50 WHERE rule_code = 'R1'");
$r = (new StatementImporter(db()))->rescore();
$score = db()->query("SELECT risk_score FROM transactions WHERE receipt_no = 'TJ296YKJBA'")->fetchColumn();
check('Re-score applies new rule weights (R1 weight 50)', (int) $score === 50, (string) $score);

$user = db()->query('SELECT * FROM users WHERE user_id = 2')->fetch();
$rs = new ReportService(db(), $user);
$sum = $rs->summary();
check('Report: totals (in 113,200.00 / out 113,200.00)', abs($sum['total_in'] - 113200) < 0.01 && abs($sum['total_out'] - 113200) < 0.01,
      $sum['total_in'] . ' / ' . $sum['total_out']);
$other = db()->query('SELECT * FROM users WHERE user_id = 1')->fetch();
$other['role'] = 'User';
check('Access control: another user sees none of these transactions', (int) (new ReportService(db(), $other))->summary()['tx_count'] === 0);

@unlink(SQLITE_PATH);
printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed ? 1 : 0);
