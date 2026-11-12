<?php
/**
 * StatementImporter – runs the full pipeline for one uploaded statement:
 * validate -> parse -> categorise -> score -> save transactions and fraud flags.
 * The uploaded file is always deleted after processing (Data Protection Act, 2019).
 */
class StatementImporter
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Processes one uploaded statement from start to finish:
     * check the file, check for a duplicate, read it, then score and save it.
     * Returns the new statement id, how many transactions were saved and how many were flagged.
     * Any problem throws a RuntimeException; its message is shown to the user in red.
     * @return array{statement_id:int, total:int, flagged:int}
     */
    public function import(int $userId, string $tmpPath, string $originalName, string $password = '', bool $deleteAfter = true): array
    {
        // Allow up to 3 minutes for a very large statement, instead of PHP's default 30 seconds.
        @set_time_limit(180);
        try {
            // Check 1: only .pdf and .csv files.
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'csv'], true)) {
                throw new RuntimeException('Only PDF or CSV M-Pesa statements are allowed.');
            }
            // Check 2: not bigger than 10 MB.
            if (filesize($tmpPath) > MAX_UPLOAD_SIZE) {
                throw new RuntimeException('The file is larger than 10 MB.');
            }
            // Check 3: the real file type (MIME), read from the content. A .docx renamed to .pdf is rejected here.
            if (class_exists('finfo')) {
                $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
                $allowed = $ext === 'pdf'
                    ? ['application/pdf', 'application/x-pdf']
                    : ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'];
                if (!in_array($mime, $allowed, true)) {
                    throw new RuntimeException('File rejected: its type (' . $mime . ') is not a valid ' . strtoupper($ext) . ' statement.');
                }
            }
            // Check 4: a real PDF always starts with the characters "%PDF-".
            $head = (string) file_get_contents($tmpPath, false, null, 0, 5);
            if ($ext === 'pdf' && $head !== '%PDF-') {
                throw new RuntimeException('File rejected: it is not a valid PDF document.');
            }

            // Check 5: duplicates. SHA-256 gives every file a unique fingerprint. The same file uploaded again has
            // the same fingerprint, and statements.file_hash is UNIQUE in the database.
            $hash = hash_file('sha256', $tmpPath);
            $dup = $this->db->prepare('SELECT statement_id FROM statements WHERE file_hash = ?');
            $dup->execute([$hash]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('This statement has already been uploaded (duplicate detected by file hash).');
            }

            // Read the transactions: PDF (needs the SMS password) or CSV.
            $parser = new StatementParser();
            $rows = $ext === 'pdf' ? $parser->parsePdf($tmpPath, $password) : $parser->parseCsv($tmpPath);
            if (!$rows) {
                throw new RuntimeException('No completed M-Pesa transactions were found in the file.');
            }

            // Score, categorise and save everything.
            return $this->save($userId, $originalName, $hash, strtoupper($ext), $rows);
        } finally {
            // "finally" runs whether the import worked or failed, so the uploaded file is ALWAYS deleted.
            // The statement is never kept on the server (Data Protection Act, 2019).
            if ($deleteAfter) {
                @unlink($tmpPath);
            }
        }
    }

    // Saves one statement with all its transactions and flags.
    private function save(int $userId, string $name, string $hash, string $type, array $rows): array
    {
        $categorizer = new Categorizer($this->db);
        $engine = new FraudEngine($this->db);
        // Run the 8 fraud rules first, before anything is written.
        $scored = $engine->score($rows);

        // Database transaction: all the inserts below succeed together, or none of them is kept.
        // If anything fails half-way, rollBack() undoes everything, so a half-saved statement can never exist.
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('INSERT INTO statements (user_id, file_name, file_hash, source_type, period_start, period_end, total_records, uploaded_at)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([$userId, mb_substr(basename($name), 0, 255), $hash, $type,
                          substr($rows[0]['completion_time'], 0, 10), substr(end($rows)['completion_time'], 0, 10),
                          count($rows), now()]);
            // The id MySQL just gave the new statement. Every transaction row points to it.
            $statementId = (int) $this->db->lastInsertId();

            $flagged = $this->insertTransactions($statementId, $scored, $categorizer, $engine);
            $this->db->commit();
        } catch (Throwable $ex) {
            $this->db->rollBack();
            throw $ex;
        }
        // Only after a successful save: record the upload in the activity log.
        log_activity($userId, "Uploaded statement #$statementId ($type, " . count($rows) . " transactions, $flagged flagged)");
        return ['statement_id' => $statementId, 'total' => count($rows), 'flagged' => $flagged];
    }

    // Inserts every transaction with its category and risk score. For each transaction scoring 30 or more,
    // it also inserts one row in flagged_transactions per rule broken, with that rule's weight and the status Pending.
    private function insertTransactions(int $statementId, array $scored, Categorizer $categorizer, FraudEngine $engine): int
    {
        // The two INSERTs are prepared once and run many times with different values: faster, and safe.
        $tx = $this->db->prepare('INSERT INTO transactions (statement_id, category_id, receipt_no, completion_time, details, counterparty, direction, amount, balance, risk_score, risk_level)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $flag = $this->db->prepare('INSERT INTO flagged_transactions (transaction_id, rule_id, score, review_status) VALUES (?, ?, ?, ?)');
        $flagged = 0;
        foreach ($scored as $t) {
            $tx->execute([$statementId, $categorizer->categorize($t['details']), $t['receipt_no'], $t['completion_time'],
                          $t['details'], $t['counterparty'], $t['direction'], $t['amount'], $t['balance'],
                          $t['risk_score'], $t['risk_level']]);
            if ($t['risk_score'] >= MEDIUM_RISK) {
                $txId = (int) $this->db->lastInsertId();
                foreach ($t['rules'] as $code) {
                    $flag->execute([$txId, $engine->ruleId($code), $engine->weight($code), 'Pending']);
                }
                $flagged++;
            }
        }
        return $flagged;
    }

    /**
     * Re-runs categorisation and fraud scoring on stored statements (after the administrator
     * changes rules, thresholds, the blacklist or categories). Existing review decisions are kept
     * for transactions that are still flagged by the same rule.
     */
    public function rescore(?int $statementId = null): array
    {
        // Allow up to 5 minutes when re-scoring every statement.
        @set_time_limit(300);
        $ids = $statementId ? [$statementId]
             : $this->db->query('SELECT statement_id FROM statements')->fetchAll(PDO::FETCH_COLUMN);
        $categorizer = new Categorizer($this->db);
        $engine = new FraudEngine($this->db);
        $totalFlagged = 0;

        $get = $this->db->prepare('SELECT * FROM transactions WHERE statement_id = ? ORDER BY completion_time, transaction_id');
        $upd = $this->db->prepare('UPDATE transactions SET category_id = ?, risk_score = ?, risk_level = ? WHERE transaction_id = ?');
        $old = $this->db->prepare('SELECT f.rule_id, f.review_status, f.reviewed_by, f.reviewed_at FROM flagged_transactions f WHERE f.transaction_id = ?');
        $del = $this->db->prepare('DELETE FROM flagged_transactions WHERE transaction_id = ?');
        $ins = $this->db->prepare('INSERT INTO flagged_transactions (transaction_id, rule_id, score, review_status, reviewed_by, reviewed_at) VALUES (?, ?, ?, ?, ?, ?)');

        $this->db->beginTransaction();
        try {
            foreach ($ids as $sid) {
                $get->execute([$sid]);
                $rows = $get->fetchAll();
                foreach ($rows as &$r) {
                    $r['amount'] = (float) $r['amount'];
                    $r['balance'] = (float) $r['balance'];
                }
                unset($r);
                foreach ($engine->score($rows) as $t) {
                    $upd->execute([$categorizer->categorize($t['details']), $t['risk_score'], $t['risk_level'], $t['transaction_id']]);
                    $old->execute([$t['transaction_id']]);
                    // Remember the user's earlier decisions (Confirmed / Dismissed), so re-scoring does not undo them.
                    $previous = [];
                    foreach ($old->fetchAll() as $o) {
                        $previous[(int) $o['rule_id']] = $o;
                    }
                    // Remove the old flags, then add the new ones if the transaction is still flagged.
                    $del->execute([$t['transaction_id']]);
                    if ($t['risk_score'] >= MEDIUM_RISK) {
                        foreach ($t['rules'] as $code) {
                            $rid = $engine->ruleId($code);
                            $p = $previous[$rid] ?? null;
                            $ins->execute([$t['transaction_id'], $rid, $engine->weight($code), $p['review_status'] ?? 'Pending',
                                           $p['reviewed_by'] ?? null, $p['reviewed_at'] ?? null]);
                        }
                        $totalFlagged++;
                    }
                }
            }
            $this->db->commit();
        } catch (Throwable $ex) {
            $this->db->rollBack();
            throw $ex;
        }
        return ['statements' => count($ids), 'flagged' => $totalFlagged];
    }
}
