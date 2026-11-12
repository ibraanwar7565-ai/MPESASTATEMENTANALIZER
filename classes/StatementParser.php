<?php
/**
 * StatementParser – reads M-Pesa statements.
 *  - PDF: decrypts the password-protected statement with qpdf, extracts the text with
 *         Smalot PdfParser and splits it into transaction records.
 *  - CSV: reads the export format "Receipt No, Completion Time, Details,
 *         Transaction Status, Paid In, Withdrawn, Balance".
 * Every parser returns rows sorted from the oldest to the newest transaction.
 */
use Smalot\PdfParser\Parser;

class StatementParser
{
    // RECORD is a regular expression (a text pattern) that recognises ONE row of an M-Pesa statement:
    //   ([A-Z0-9]{10})               the receipt number, e.g. TJ296YKJBA
    //   (\d{4}-\d{2}-\d{2} ...)       the completion date and time
    //   (.{1,400}?)                  the details, up to 400 characters (they can wrap over two lines)
    //   (Completed|Failed|...)       the transaction status
    //   (-?[\d,]+\.\d{2}) 1 to 3 times the amounts: paid in and/or withdrawn, then the balance
    // The 400-character limit stops a damaged PDF from keeping the pattern busy for a long time.
    // Receipt | Completion time | Details (may wrap over lines) | Status | Paid in / Withdrawn | Balance
    private const RECORD = '/\b([A-Z0-9]{10})\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(.{1,400}?)\s+'
                         . '(Completed|Failed|Reversed|Cancelled)\s+(-?[\d,]+\.\d{2})'
                         . '(?:\s+(-?[\d,]+\.\d{2}))?(?:\s+(-?[\d,]+\.\d{2}))?/s';

    // Opens a password-protected PDF statement and returns its transactions.
    public function parsePdf(string $path, string $password): array
    {
        // Step 1: qpdf writes an unlocked copy of the PDF, using the password the user got by SMS.
        // escapeshellarg() wraps each value safely, so a password cannot sneak extra commands into the shell.
        $plain = UPLOAD_PATH . uniqid('dec_', true) . '.pdf';
        $cmd = escapeshellarg(qpdf_bin()) . ' --password=' . escapeshellarg($password)
             . ' --decrypt ' . escapeshellarg($path) . ' ' . escapeshellarg($plain) . ' 2>&1';
        exec($cmd, $out, $code);
        // qpdf exit code 0 = success, 3 = success with warnings. Anything else: wrong password or damaged file.
        if (($code !== 0 && $code !== 3) || !is_file($plain)) {      // 3 = success with warnings
            @unlink($plain);
            if ($code === 127 || stripos(implode(' ', $out), 'not recognized') !== false) {
                throw new RuntimeException('qpdf was not found. Check tools/qpdf/bin or install qpdf.');
            }
            throw new RuntimeException('Wrong statement password, or the PDF file is damaged.');
        }
        try {
            // Step 2: Smalot PdfParser reads all the text out of the unlocked PDF.
            $text = (new Parser())->parseFile($plain)->getText();
        } finally {
            @unlink($plain);                                          // never keep the decrypted copy
        }
        // Step 3: turn the text into transactions.
        return $this->parseText($text);
    }

    // Finds every statement row in the text and turns each one into a transaction.
    public function parseText(string $text): array
    {
        if (strlen($text) > 20 * 1024 * 1024) {
            throw new RuntimeException('The statement is too large to analyse. Request a shorter period.');
        }
        // Tabs become spaces and Windows line endings are removed, so the pattern sees clean text.
        $text = str_replace(["\t", "\r"], [' ', ''], $text);
        // Find all rows at once. Each $m holds the parts of one row: receipt, time, details, status, amounts.
        preg_match_all(self::RECORD, $text, $matches, PREG_SET_ORDER);

        $rows = [];
        foreach ($matches as $m) {
            // Failed, reversed and cancelled transactions moved no money, so they are skipped.
            if ($m[4] !== 'Completed') {
                continue;
            }
            $nums = array_values(array_filter([$m[5], $m[6] ?? '', $m[7] ?? ''], fn($v) => $v !== ''));
            if (count($nums) < 2) {
                continue;
            }
            // The last number on a row is always the balance.
            $balance = $this->num(array_pop($nums));
            // Either one signed amount, or "Paid In" followed by "Withdrawn"
            $amount = count($nums) === 1 ? $this->num($nums[0]) : $this->num($nums[0]) - abs($this->num($nums[1]));
            $rows[] = $this->row($m[1], preg_replace('/\s+/', ' ', $m[2]), $m[3], $amount, $balance);
        }
        return $this->sorted($rows);
    }

    // Reads a CSV export of the statement. Columns are found by their header name, so their order does not matter.
    public function parseCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new RuntimeException('Could not open the CSV file.');
        }
        // First line = column headers. They are lower-cased, and the invisible BOM character that Excel adds is removed.
        $header = fgetcsv($fh, 0, ',', '"', '\\');
        $header = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header ?: []);
        // $col(['paid in', 'credit']) returns the position of the first header that matches one of the names.
        $col = function (array $names) use ($header): ?int {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false) {
                    return $i;
                }
            }
            return null;
        };
        $iReceipt = $col(['receipt no', 'receipt no.', 'receipt']);
        $iTime    = $col(['completion time', 'date', 'time']);
        $iDetails = $col(['details', 'description']);
        $iStatus  = $col(['transaction status', 'status']);
        $iIn      = $col(['paid in', 'paid_in', 'credit']);
        $iOut     = $col(['withdrawn', 'paid out', 'debit']);
        $iBal     = $col(['balance']);
        if ($iReceipt === null || $iTime === null || $iDetails === null || $iBal === null || ($iIn === null && $iOut === null)) {
            fclose($fh);
            throw new RuntimeException('The CSV file does not have the M-Pesa statement columns '
                . '(Receipt No, Completion Time, Details, Transaction Status, Paid In, Withdrawn, Balance).');
        }

        $rows = [];
        // Read the other lines one by one. Lines without a valid 10-character receipt, or not Completed, are skipped.
        while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (count($r) < 3 || !preg_match('/^[A-Z0-9]{10}$/', trim($r[$iReceipt] ?? ''))) {
                continue;
            }
            if ($iStatus !== null && strcasecmp(trim($r[$iStatus] ?? ''), 'Completed') !== 0) {
                continue;
            }
            $time = date('Y-m-d H:i:s', strtotime(trim($r[$iTime])));
            $in   = $iIn !== null ? abs($this->num($r[$iIn] ?? '')) : 0;
            $out  = $iOut !== null ? abs($this->num($r[$iOut] ?? '')) : 0;
            $receipt = trim($r[$iReceipt]);
            $rows[] = $this->row($receipt, $time, $r[$iDetails], $in - $out, $this->num($r[$iBal] ?? '0'));
        }
        fclose($fh);
        return $this->sorted($rows);
    }

    // Pulls the other party's number out of the details. Rules R4 (new recipient) and R5 (blacklist) use it.
    public static function counterparty(string $details): string
    {
        // e.g. "Customer Transfer to 0712***678 - JOHN DOE", "Pay Bill to 888880 - KPLC", "Agent Till 301221"
        return preg_match('/(?:to|from|Till)\s+([0-9*]{5,13})/i', $details, $m) ? $m[1] : '';
    }

    // Builds one transaction in the same shape for PDF and CSV. A negative amount means money going OUT.
    private function row(string $receipt, string $time, string $details, float $amount, float $balance): array
    {
        $details = trim(preg_replace('/\s+/', ' ', $details));
        return [
            'receipt_no'      => $receipt,
            'completion_time' => $time,
            'details'         => mb_substr($details, 0, 255),
            'direction'       => $amount < 0 ? 'OUT' : 'IN',
            'amount'          => round(abs($amount), 2),
            'balance'         => round($balance, 2),
            'counterparty'    => self::counterparty($details),
        ];
    }

    // Puts the transactions in time order, oldest first. The fraud rules look back in time, so the order matters.
    private function sorted(array $rows): array
    {
        // Statements list the newest first; keep the original order for rows with the same time
        foreach ($rows as $i => &$r) {
            $r['_seq'] = $i;
        }
        unset($r);
        usort($rows, fn($a, $b) => strcmp($a['completion_time'], $b['completion_time']) ?: $a['_seq'] <=> $b['_seq']);
        foreach ($rows as &$r) {
            unset($r['_seq']);
        }
        unset($r);
        return $rows;
    }

    // Turns text such as "KES 1,250.00" into the number 1250.0.
    private function num(string $v): float
    {
        $v = trim(str_replace([',', 'KES', 'Ksh', ' '], '', $v));
        return $v === '' ? 0.0 : (float) $v;
    }
}
