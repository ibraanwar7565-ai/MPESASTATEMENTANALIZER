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
    private array $rules;      // active rules keyed by code: ['rule_id'=>.., 'threshold'=>.., 'weight'=>..]
    private array $blacklist;  // numbers / tills flagged by the administrator

    public function __construct(PDO $db)
    {
        $this->rules = $db->query('SELECT rule_code, rule_id, rule_name, threshold, weight
                                   FROM fraud_rules WHERE is_active = 1')
                          ->fetchAll(PDO::FETCH_UNIQUE);
        $this->blacklist = array_map('strtolower', $db->query('SELECT identifier FROM blacklist')
                              ->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function isCharge(array $t): bool
    {
        return (bool) preg_match('/\bcharge\b/i', $t['details']);
    }

    public function ruleId(string $code): ?int
    {
        return isset($this->rules[$code]) ? (int) $this->rules[$code]['rule_id'] : null;
    }

    /** Points a rule adds to the risk score (stored as flagged_transactions.score). */
    public function weight(string $code): int
    {
        return (int) ($this->rules[$code]['weight'] ?? 0);
    }

    public function score(array $txs): array
    {
        $txs = array_values($txs);
        $out = [];
        foreach ($txs as $t) {
            if ($t['direction'] === 'OUT' && !self::isCharge($t)) {
                $out[] = (float) $t['amount'];
            }
        }
        $mean = $out ? array_sum($out) / count($out) : 0;
        $sd = $out ? sqrt(array_sum(array_map(fn($a) => ($a - $mean) ** 2, $out)) / count($out)) : 0;
        $seen = [];

        foreach ($txs as $i => &$t) {
            $hits = [];
            $time = strtotime($t['completion_time']);
            if ($t['direction'] === 'OUT' && !self::isCharge($t)) {
                $hour = (int) date('G', $time);
                $cp = $t['counterparty'];
                if ($sd > 0 && $t['amount'] > $mean + $this->th('R1') * $sd)       $hits[] = 'R1';
                if ($hour < $this->th('R2'))                                       $hits[] = 'R2';
                if ($this->burst($txs, $i, $time) >= $this->th('R3'))              $hits[] = 'R3';
                if ($cp !== '' && !isset($seen[$cp]) && $t['amount'] > $this->th('R4')) $hits[] = 'R4';
                if ($cp !== '' && in_array(strtolower($cp), $this->blacklist, true)) $hits[] = 'R5';
                if ($this->reversalPattern($txs, $i, $time))                       $hits[] = 'R6';
                if ($this->drained($txs, $i, $time))                               $hits[] = 'R7';
                if ($this->loanThenOut($txs, $i, $time))                           $hits[] = 'R8';
                if ($cp !== '') {
                    $seen[$cp] = true;
                }
            }
            $hits = array_values(array_filter($hits, fn($c) => isset($this->rules[$c])));
            $t['rules'] = $hits;
            $t['risk_score'] = min(100, array_sum(array_map(fn($c) => (int) $this->rules[$c]['weight'], $hits)));
            $t['risk_level'] = $t['risk_score'] >= HIGH_RISK ? 'High'
                             : ($t['risk_score'] >= MEDIUM_RISK ? 'Medium' : 'Low');
        }
        unset($t);
        return $txs;
    }

    private function th(string $code): float
    {
        return (float) ($this->rules[$code]['threshold'] ?? INF);
    }

    // R3: number of outgoing transfers (charges excluded) in the 10 minutes up to this one
    private function burst(array $txs, int $i, int $time): int
    {
        $n = 0;
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
        $window = $this->th('R6') * 60;
        for ($j = $i - 1; $j >= 0; $j--) {
            $prev = $txs[$j];
            if ($time - strtotime($prev['completion_time']) > $window) {
                break;
            }
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
            if ($txs[$j]['direction'] === 'IN'
                && preg_match('/OverDraft|M-Shwari Loan|KCB M-PESA Loan/i', $txs[$j]['details'])
                && $txs[$i]['amount'] >= 0.9 * $txs[$j]['amount']) {
                return true;
            }
        }
        return false;
    }
}
