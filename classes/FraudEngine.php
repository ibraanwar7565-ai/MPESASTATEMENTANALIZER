<?php
/**
 * FraudEngine – rule-based risk scoring of parsed M-Pesa transactions.
 *
 * Only outgoing (OUT) transactions are scored. Transaction charges are excluded from the
 * statistics and from scoring. The risk score is the sum of the weights of the rules a
 * transaction triggers (maximum 100): Low < 30, Medium 30-59, High >= 60.
 *
 *  R1 Unusually large amount (z-score)      R5 Blacklisted counterparty
 *  R2 Odd-hour activity (00:00-05:00)       R6 Reversal-scam pattern
 *  R3 Rapid successive transfers            R7 Balance draining
 *  R4 New recipient, high value             R8 Loan-then-withdraw
 */
class FraudEngine
{
    // Both lists are loaded from the database when the engine is created, so admin changes apply on the next run.
    private array $rules;      // active rules keyed by code: ['rule_id'=>.., 'threshold'=>.., 'weight'=>..]
    private array $blacklist;  // numbers / tills flagged by the administrator

    public function __construct(PDO $db)
    {
        // FETCH_UNIQUE keys the rules by their code, so a rule is read as $this->rules['R1']['threshold'].
        // Only rules that are switched on (is_active = 1) are loaded, so a switched-off rule can never fire.
        $this->rules = $db->query('SELECT rule_code, rule_id, rule_name, threshold, weight
                                   FROM fraud_rules WHERE is_active = 1')
                          ->fetchAll(PDO::FETCH_UNIQUE);
        // Blacklisted numbers, in lower case so the comparison ignores upper/lower case.
        $this->blacklist = array_map('strtolower', $db->query('SELECT identifier FROM blacklist')
                              ->fetchAll(PDO::FETCH_COLUMN));
    }

    // True for M-Pesa fee rows such as "Customer Transfer of Funds Charge". Fees are never scored.
    public static function isCharge(array $t): bool
    {
        return (bool) preg_match('/\bcharge\b/i', $t['details']);
    }

    // The database id of a rule, needed when a flag is saved in flagged_transactions.
    public function ruleId(string $code): ?int
    {
        return isset($this->rules[$code]) ? (int) $this->rules[$code]['rule_id'] : null;
    }

    /** Points a rule adds to the risk score (stored as flagged_transactions.score). */
    public function weight(string $code): int
    {
        return (int) ($this->rules[$code]['weight'] ?? 0);
    }

    // Scores every transaction of one statement. This is the heart of the fraud detection.
    // In:  the transactions in time order (from StatementParser).
    // Out: the same transactions, each with three new fields:
    //        rules       the codes of the rules it broke, e.g. ['R2', 'R3']
    //        risk_score  the weights of those rules added up, 0 to 100
    //        risk_level  Low, Medium or High
    public function score(array $txs): array
    {
        $txs = array_values($txs);
        // Step 1: collect every outgoing amount (fees excluded) to learn what is "normal" for this user.
        $out = [];
        foreach ($txs as $t) {
            if ($t['direction'] === 'OUT' && !self::isCharge($t)) {
                $out[] = (float) $t['amount'];
            }
        }
        // The average outgoing amount, and the standard deviation (how far amounts usually are from the average). R1 uses both.
        $mean = $out ? array_sum($out) / count($out) : 0;
        $sd = $out ? sqrt(array_sum(array_map(fn($a) => ($a - $mean) ** 2, $out)) / count($out)) : 0;
        // Numbers and tills already paid earlier in the statement. R4 (new recipient) uses this.
        $seen = [];

        // Step 2: test each transaction. "&$t" lets the loop write the results straight into the transaction.
        foreach ($txs as $i => &$t) {
            $hits = [];
            $time = strtotime($t['completion_time']);
            if ($t['direction'] === 'OUT' && !self::isCharge($t)) {
                // Only money going OUT is scored. Each rule below is a yes/no test; a "yes" adds the rule code to $hits.
                $hour = (int) date('G', $time);
                $cp = $t['counterparty'];
                // Default thresholds are shown; th('R1') etc. read the current values from the database (Fraud Rules page).
                // R1  amount more than 3 standard deviations above this user's average
                // R2  sent before 05:00
                // R3  3 or more outgoing transfers within 10 minutes
                // R4  first payment to this number or till, and above KES 10,000
                // R5  the recipient is on the blacklist
                // R6  reversal scam: money received, then a similar amount sent out within 60 minutes
                // R7  more than 80% of the balance gone within one hour
                // R8  Fuliza / M-Shwari / KCB loan, then 90% or more of it sent out within 30 minutes
                if ($sd > 0 && $t['amount'] > $mean + $this->th('R1') * $sd)       $hits[] = 'R1';
                if ($hour < $this->th('R2'))                                       $hits[] = 'R2';
                if ($this->burst($txs, $i, $time) >= $this->th('R3'))              $hits[] = 'R3';
                if ($cp !== '' && !isset($seen[$cp]) && $t['amount'] > $this->th('R4')) $hits[] = 'R4';
                if ($cp !== '' && in_array(strtolower($cp), $this->blacklist, true)) $hits[] = 'R5';
                if ($this->reversalPattern($txs, $i, $time))                       $hits[] = 'R6';
                if ($this->drained($txs, $i, $time))                               $hits[] = 'R7';
                if ($this->loanThenOut($txs, $i, $time))                           $hits[] = 'R8';
                // Remember this recipient, so a later payment to them is no longer "new" for R4.
                if ($cp !== '') {
                    $seen[$cp] = true;
                }
            }
            // Step 3: keep only the rules that are switched on, add up their weights (never more than 100), and set the level:
            // 60 or more = High, 30 or more = Medium, below 30 = Low (HIGH_RISK and MEDIUM_RISK are set in config.php).
            $hits = array_values(array_filter($hits, fn($c) => isset($this->rules[$c])));
            $t['rules'] = $hits;
            $t['risk_score'] = min(100, array_sum(array_map(fn($c) => (int) $this->rules[$c]['weight'], $hits)));
            $t['risk_level'] = $t['risk_score'] >= HIGH_RISK ? 'High'
                             : ($t['risk_score'] >= MEDIUM_RISK ? 'Medium' : 'Low');
        }
        // Break the link made by "&$t" (a PHP safety habit after a by-reference loop).
        unset($t);
        return $txs;
    }

    // A rule's threshold. If the rule is switched off, it returns infinity, so the rule can never fire.
    private function th(string $code): float
    {
        return (float) ($this->rules[$code]['threshold'] ?? INF);
    }

    // R3: number of outgoing transfers (charges excluded) in the 10 minutes up to this one
    private function burst(array $txs, int $i, int $time): int
    {
        $n = 0;
        // Walk back from this transaction while still inside the 10-minute (600-second) window.
        for ($j = $i; $j >= 0 && $time - strtotime($txs[$j]['completion_time']) <= 600; $j--) {
            if ($txs[$j]['direction'] === 'OUT' && !self::isCharge($txs[$j])) {
                $n++;
            }
        }
        return $n;
    }

    // R6: money received, then a similar amount (within 5%) sent out within the threshold minutes
    private function reversalPattern(array $txs, int $i, int $time): bool
    {
        // The threshold is in minutes; times are compared in seconds.
        $window = $this->th('R6') * 60;
        for ($j = $i - 1; $j >= 0; $j--) {
            $prev = $txs[$j];
            if ($time - strtotime($prev['completion_time']) > $window) {
                break;
            }
            // Money came in ("Funds received") and the amount going out now is within 5% of it.
            if ($prev['direction'] === 'IN' && stripos($prev['details'], 'Funds received') !== false
                && abs($prev['amount'] - $txs[$i]['amount']) <= 0.05 * $prev['amount']) {
                return true;
            }
        }
        return false;
    }

    // R7: balance fell by more than the threshold percentage within one hour
    private function drained(array $txs, int $i, int $time): bool
    {
        for ($j = $i - 1; $j >= 0 && $time - strtotime($txs[$j]['completion_time']) <= 3600; $j--) {
            // The balance just before that earlier transaction. If the balance now is more than 80% lower, the account is being drained.
            $start = $txs[$j]['balance'] + ($txs[$j]['direction'] === 'OUT' ? $txs[$j]['amount'] : 0);
            if ($start > 0 && ($start - $txs[$i]['balance']) / $start * 100 > $this->th('R7')) {
                return true;
            }
        }
        return false;
    }

    // R8: Fuliza / M-Shwari / KCB M-PESA loan followed by an outflow of most of it within the threshold minutes
    private function loanThenOut(array $txs, int $i, int $time): bool
    {
        $window = $this->th('R8') * 60;
        for ($j = $i - 1; $j >= 0 && $time - strtotime($txs[$j]['completion_time']) <= $window; $j--) {
            // "OverDraft of Credit Party" is how Fuliza appears on statements. 90% or more of the loan leaving counts.
            if ($txs[$j]['direction'] === 'IN'
                && preg_match('/OverDraft|M-Shwari Loan|KCB M-PESA Loan/i', $txs[$j]['details'])
                && $txs[$i]['amount'] >= 0.9 * $txs[$j]['amount']) {
                return true;
            }
        }
        return false;
    }
}
