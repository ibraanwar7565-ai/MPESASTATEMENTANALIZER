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
    // Receipt | Completion time | Details (may wrap over lines) | Status | Paid in / Withdrawn | Balance
    private const RECORD = '/\b([A-Z0-9]{10})\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(.+?)\s+'
                         . '(Completed|Failed|Reversed|Cancelled)\s+(-?[\d,]+\.\d{2})'
                         . '(?:\s+(-?[\d,]+\.\d{2}))?(?:\s+(-?[\d,]+\.\d{2}))?/s';

    public function parsePdf(string $path, string $password): array
    {
        $plain = UPLOAD_PATH . uniqid('dec_', true) . '.pdf';
        $cmd = escapeshellarg(qpdf_bin()) . ' --password=' . escapeshellarg($password)
             . ' --decrypt ' . escapeshellarg($path) . ' ' . escapeshellarg($plain) . ' 2>&1';
        exec($cmd, $out, $code);
        if (($code !== 0 && $code !== 3) || !is_file($plain)) {      // 3 = success with warnings
            @unlink($plain);
            if ($code === 127 || stripos(implode(' ', $out), 'not recognized') !== false) {
                throw new RuntimeException('qpdf was not found. Check tools/qpdf/bin or install qpdf.');
            }
            throw new RuntimeException('Wrong statement password, or the PDF file is damaged.');
        }
        try {
            $text = (new Parser())->parseFile($plain)->getText();
        } finally {
            @unlink($plain);                                          // never keep the decrypted copy
        }
        return $this->parseText($text);
    }

    public function parseText(string $text): array
    {
        $text = str_replace(["\t", "\r"], [' ', ''], $text);
        preg_match_all(self::RECORD, $text, $matches, PREG_SET_ORDER);

        $rows = [];
        foreach ($matches as $m) {
            if ($m[4] !== 'Completed') {
                continue;
            }
            $nums = array_values(array_filter([$m[5], $m[6] ?? '', $m[7] ?? ''], fn($v) => $v !== ''));
            if (count($nums) < 2) {
                continue;
            }
            $balance = $this->num(array_pop($nums));
            // Either one signed amount, or "Paid In" followed by "Withdrawn"
            $amount = count($nums) === 1 ? $this->num($nums[0]) : $this->num($nums[0]) - abs($this->num($nums[1]));
            $rows[] = $this->row($m[1], preg_replace('/\s+/', ' ', $m[2]), $m[3], $amount, $balance);
        }
        return $this->sorted($rows);
    }

    public function parseCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new RuntimeException('Could not open the CSV file.');
        }
        $header = fgetcsv($fh, 0, ',', '"', '\\');
        $header = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header ?: []);
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

    public static function counterparty(string $details): string
    {
        // e.g. "Customer Transfer to 0712***678 - JOHN DOE", "Pay Bill to 888880 - KPLC", "Agent Till 301221"
        return preg_match('/(?:to|from|Till)\s+([0-9*xX]{5,13})/i', $details, $m) ? strtolower($m[1]) : '';
    }

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

    private function num(string $v): float
    {
        $v = trim(str_replace([',', 'KES', 'Ksh', ' '], '', $v));
        return $v === '' ? 0.0 : (float) $v;
    }
}
