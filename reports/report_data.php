<?php
/**
 * Builds the content of each report as sections of [heading, column headers, rows, numeric column indexes].
 * The same data feeds the on-screen preview, the PDF (Dompdf) and the CSV export.
 */
function build_report(string $type, ReportService $rs, PDO $db): array
{
    $n = fn($v) => number_format((float) $v, 2);
    switch ($type) {
        case 'flagged':
            $names = ReportService::ruleNames($db);
            $rows = array_map(fn($r) => [
                $r['receipt_no'], date('d M Y H:i', strtotime($r['completion_time'])), $r['details'], $n($r['amount']),
                implode('; ', array_map(fn($c) => $c . ' ' . ($names[$c] ?? ''), explode(',', $r['rule_codes']))),
                $r['risk_score'] . ' (' . $r['risk_level'] . ')', $r['review_status'],
            ], $rs->flagged(''));
            return [['Flagged transactions', ['Receipt', 'Date', 'Details', 'Amount (KES)', 'Rules triggered', 'Risk', 'Review'], $rows, [3]]];

        case 'recipients':
            $rows = array_map(fn($r) => [$r['details'], $r['tx_count'], $n($r['total']), $r['max_risk']], $rs->topRecipients(20));
            return [['Top 20 recipients by amount', ['Recipient', 'Transactions', 'Total (KES)', 'Highest risk score'], $rows, [1, 2, 3]]];

        case 'charges':
            $rows = array_map(fn($r) => [date('F Y', strtotime($r['month'] . '-01')), $r['tx_count'], $n($r['total'])], $rs->charges());
            $total = array_sum(array_map(fn($r) => (float) str_replace(',', '', $r[2]), $rows));
            if ($rows) {
                $rows[] = ['Total', array_sum(array_column($rows, 1)), $n($total)];
            }
            return [['Transaction charges per month', ['Month', 'Charges', 'Amount (KES)'], $rows, [1, 2]]];

        case 'transactions':
            $rows = array_map(fn($t) => [
                $t['receipt_no'], $t['completion_time'], $t['details'], $t['category'],
                $t['direction'] === 'IN' ? $n($t['amount']) : '', $t['direction'] === 'OUT' ? $n($t['amount']) : '',
                $n($t['balance']), $t['direction'] === 'OUT' ? $t['risk_score'] . ' ' . $t['risk_level'] : '',
            ], $rs->transactions());
            return [['All transactions', ['Receipt', 'Completion time', 'Details', 'Category', 'Paid in', 'Withdrawn', 'Balance', 'Risk'], $rows, [4, 5, 6]]];

        default: // summary
            $s = $rs->summary();
            $overview = [
                ['Total money in', $n($s['total_in'])],
                ['Total money out', $n($s['total_out'])],
                ['Net cash flow', $n($s['total_in'] - $s['total_out'])],
                ['Transaction charges', $n($s['charges'])],
                ['Number of transactions', $s['tx_count']],
                ['Flagged transactions (score >= ' . MEDIUM_RISK . ')', $s['flagged']],
            ];
            $monthly = array_map(fn($m) => [date('F Y', strtotime($m['month'] . '-01')), $m['tx_count'], $n($m['money_in']), $n($m['money_out']),
                                            $n($m['money_in'] - $m['money_out'])], $rs->monthly());
            $cats = $rs->byCategory('OUT');
            $outTotal = array_sum(array_column($cats, 'total')) ?: 1;
            $catRows = array_map(fn($c) => [$c['category'], $c['tx_count'], $n($c['total']), number_format($c['total'] / $outTotal * 100, 1) . '%'], $cats);
            return [
                ['Overview', ['Measure', 'Value (KES)'], $overview, [1]],
                ['Money in and out per month', ['Month', 'Transactions', 'Money in', 'Money out', 'Net'], $monthly, [1, 2, 3, 4]],
                ['Spending by category', ['Category', 'Transactions', 'Amount (KES)', 'Share'], $catRows, [1, 2, 3]],
            ];
    }
}
