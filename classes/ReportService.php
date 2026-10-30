<?php
/**
 * ReportService – read-only queries used by the dashboard and the reports module.
 * Every query is limited to the logged-in user's statements (administrators see all).
 */
class ReportService
{
    public function __construct(private PDO $db, private array $user, private ?int $statementId = null)
    {
    }

    private function run(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    private function scope(array &$params): string
    {
        return scope_sql($this->user, $this->statementId, $params);
    }

    public function summary(): array
    {
        $p = [];
        $w = $this->scope($p);
        $row = $this->run("SELECT COUNT(*) AS tx_count,
                                  COALESCE(SUM(CASE WHEN t.direction = 'IN' THEN t.amount END), 0) AS total_in,
                                  COALESCE(SUM(CASE WHEN t.direction = 'OUT' THEN t.amount END), 0) AS total_out,
                                  COALESCE(SUM(CASE WHEN c.name = 'Charges' THEN t.amount END), 0) AS charges,
                                  COALESCE(SUM(CASE WHEN t.risk_level = 'High' THEN 1 ELSE 0 END), 0) AS high_risk,
                                  COALESCE(SUM(CASE WHEN t.risk_score >= " . MEDIUM_RISK . " THEN 1 ELSE 0 END), 0) AS flagged,
                                  MIN(t.completion_time) AS first_tx, MAX(t.completion_time) AS last_tx
                           FROM transactions t
                           JOIN statements s ON s.statement_id = t.statement_id
                           LEFT JOIN categories c ON c.category_id = t.category_id
                           WHERE 1 = 1 $w", $p)[0];
        $p2 = [];
        $w2 = $this->scope($p2);
        $row['pending'] = (int) ($this->run("SELECT COUNT(DISTINCT f.transaction_id) AS n
                                             FROM flagged_transactions f
                                             JOIN transactions t ON t.transaction_id = f.transaction_id
                                             JOIN statements s ON s.statement_id = t.statement_id
                                             WHERE f.review_status = 'Pending' $w2", $p2)[0]['n'] ?? 0);
        $last = $this->run("SELECT t.balance FROM transactions t JOIN statements s ON s.statement_id = t.statement_id
                            WHERE 1 = 1 $w ORDER BY t.completion_time DESC, t.transaction_id DESC LIMIT 1", $p);
        $row['closing_balance'] = $last[0]['balance'] ?? 0;
        return $row;
    }

    public function monthly(): array
    {
        $p = [];
        $w = $this->scope($p);
        $m = sql_month('t.completion_time');
        return $this->run("SELECT $m AS month,
                                  COALESCE(SUM(CASE WHEN t.direction = 'IN' THEN t.amount END), 0) AS money_in,
                                  COALESCE(SUM(CASE WHEN t.direction = 'OUT' THEN t.amount END), 0) AS money_out,
                                  COUNT(*) AS tx_count
                           FROM transactions t JOIN statements s ON s.statement_id = t.statement_id
                           WHERE 1 = 1 $w GROUP BY $m ORDER BY month", $p);
    }

    public function byCategory(string $direction = 'OUT'): array
    {
        $p = [$direction];
        $w = $this->scope($p);
        return $this->run("SELECT COALESCE(c.name, 'Uncategorised') AS category, COUNT(*) AS tx_count, SUM(t.amount) AS total
                           FROM transactions t
                           JOIN statements s ON s.statement_id = t.statement_id
                           LEFT JOIN categories c ON c.category_id = t.category_id
                           WHERE t.direction = ? $w
                           GROUP BY COALESCE(c.name, 'Uncategorised') ORDER BY total DESC", $p);
    }

    public function topRecipients(int $limit = 10): array
    {
        $p = [];
        $w = $this->scope($p);
        $key = "CASE WHEN t.counterparty = '' OR t.counterparty IS NULL THEN t.details ELSE t.counterparty END";
        return $this->run("SELECT $key AS recipient_key, MAX(t.details) AS details, COUNT(*) AS tx_count, SUM(t.amount) AS total,
                                  MAX(t.risk_score) AS max_risk
                           FROM transactions t
                           JOIN statements s ON s.statement_id = t.statement_id
                           LEFT JOIN categories c ON c.category_id = t.category_id
                           WHERE t.direction = 'OUT' AND (c.name IS NULL OR c.name <> 'Charges') $w
                           GROUP BY $key ORDER BY total DESC LIMIT " . (int) $limit, $p);
    }

    public function charges(): array
    {
        $p = [];
        $w = $this->scope($p);
        $m = sql_month('t.completion_time');
        return $this->run("SELECT $m AS month, COUNT(*) AS tx_count, SUM(t.amount) AS total
                           FROM transactions t
                           JOIN statements s ON s.statement_id = t.statement_id
                           JOIN categories c ON c.category_id = t.category_id
                           WHERE c.name = 'Charges' $w GROUP BY $m ORDER BY month", $p);
    }

    public function riskDistribution(): array
    {
        $p = [];
        $w = $this->scope($p);
        $rows = $this->run("SELECT t.risk_level, COUNT(*) AS n FROM transactions t
                            JOIN statements s ON s.statement_id = t.statement_id
                            WHERE t.direction = 'OUT' $w GROUP BY t.risk_level", $p);
        $out = ['Low' => 0, 'Medium' => 0, 'High' => 0];
        foreach ($rows as $r) {
            $out[$r['risk_level']] = (int) $r['n'];
        }
        return $out;
    }

    public function hourly(): array
    {
        $p = [];
        $w = $this->scope($p);
        $h = sql_hour('t.completion_time');
        $rows = $this->run("SELECT $h AS hr, COUNT(*) AS n FROM transactions t
                            JOIN statements s ON s.statement_id = t.statement_id
                            WHERE t.direction = 'OUT' $w GROUP BY $h", $p);
        $out = array_fill(0, 24, 0);
        foreach ($rows as $r) {
            $out[(int) $r['hr']] = (int) $r['n'];
        }
        return $out;
    }

    /** Flagged transactions with the rules they triggered. $status: Pending|Confirmed|Dismissed|'' */
    public function flagged(string $status = '', string $level = '', int $limit = 500): array
    {
        $p = [];
        $w = $this->scope($p);
        $having = '';
        $hp = [];
        if (in_array($status, ['Pending', 'Confirmed', 'Dismissed'], true)) {
            $having .= ' AND MAX(f.review_status) = ?';
            $hp[] = $status;
        }
        if (in_array($level, ['Medium', 'High'], true)) {
            $w .= ' AND t.risk_level = ?';
            $p[] = $level;
        }
        return $this->run("SELECT t.transaction_id, t.receipt_no, t.completion_time, t.details, t.counterparty, t.amount,
                                  t.balance, t.risk_score, t.risk_level, s.statement_id, s.file_name, u.full_name,
                                  GROUP_CONCAT(r.rule_code) AS rule_codes,
                                  MAX(f.review_status) AS review_status, MAX(f.reviewed_at) AS reviewed_at
                           FROM flagged_transactions f
                           JOIN transactions t ON t.transaction_id = f.transaction_id
                           JOIN statements s ON s.statement_id = t.statement_id
                           JOIN users u ON u.user_id = s.user_id
                           JOIN fraud_rules r ON r.rule_id = f.rule_id
                           WHERE 1 = 1 $w
                           GROUP BY t.transaction_id, t.receipt_no, t.completion_time, t.details, t.counterparty, t.amount,
                                    t.balance, t.risk_score, t.risk_level, s.statement_id, s.file_name, u.full_name
                           HAVING 1 = 1 $having
                           ORDER BY t.risk_score DESC, t.completion_time DESC LIMIT " . (int) $limit, array_merge($p, $hp));
    }

    public function transactions(int $limit = 5000): array
    {
        $p = [];
        $w = $this->scope($p);
        return $this->run("SELECT t.*, COALESCE(c.name, 'Uncategorised') AS category
                           FROM transactions t
                           JOIN statements s ON s.statement_id = t.statement_id
                           LEFT JOIN categories c ON c.category_id = t.category_id
                           WHERE 1 = 1 $w ORDER BY t.completion_time, t.transaction_id LIMIT " . (int) $limit, $p);
    }

    /** Rule names keyed by code, for showing the reason behind each flag. */
    public static function ruleNames(PDO $db): array
    {
        return $db->query('SELECT rule_code, rule_name FROM fraud_rules')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
