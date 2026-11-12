<?php
/**
 * config.php: the first file every page loads.
 *
 * It does five jobs, in this order:
 *  1. Settings: app name, database details, upload folder and the risk bands.
 *  2. Finds the qpdf program, which unlocks password-protected PDF statements.
 *  3. Sets up a friendly error page, so the user never sees a blank or frozen screen.
 *  4. db(): opens ONE shared connection to MySQL (PDO) for the whole request.
 *  5. Starts a secure session, then loads the libraries, the classes and the helper functions.
 *
 * Address on Laragon: http://localhost/mpesa-analyzer
 */

// ---------- Application ----------
// define() creates a constant: a value that never changes while the system runs.
define('APP_NAME', 'PesaGuard');
define('APP_TAGLINE', 'M-Pesa Statement Analyzer & Fraud Detection System');
define('APP_ROOT', dirname(__DIR__));

// All dates and times use Kenyan time (EAT, UTC+3), the same as the M-Pesa statement.
date_default_timezone_set('Africa/Nairobi');

// ---------- Database (MySQL on Laragon) ----------
// These must match MySQL in Laragon. Laragon's default user is root with no password.
define('DB_DRIVER', getenv('PG_DB_DRIVER') ?: 'mysql');   // 'sqlite' is used only by the automated tests
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'mpesa_analyzer_db');
define('DB_USER', 'root');
define('DB_PASS', '');                                     // Laragon default: empty password
define('SQLITE_PATH', getenv('PG_SQLITE_PATH') ?: APP_ROOT . '/storage/test.sqlite');

// ---------- Uploads ----------
// Uploaded statements wait here only while they are being read; then they are deleted.
define('UPLOAD_PATH', APP_ROOT . '/storage/tmp/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);              // 10 MB

// ---------- Fraud scoring bands ----------
// Risk score 30 to 59 = Medium (flagged), 60 or more = High (flagged), below 30 = Low (not flagged).
define('MEDIUM_RISK', 30);
define('HIGH_RISK', 60);

// ---------- qpdf (decrypts password-protected statements) ----------
// Leave empty to auto-detect: the copy bundled in tools/qpdf, or qpdf placed in C:\laragon\bin\qpdf
define('QPDF_PATH', '');

// Returns the full path of qpdf. It tries each likely location in turn and uses the first one that exists.
function qpdf_bin(): string
{
    $candidates = PHP_OS_FAMILY === 'Windows'
        ? [QPDF_PATH,
           APP_ROOT . '/tools/qpdf/bin/qpdf.exe',           // bundled with the project
           'C:/laragon/bin/qpdf/bin/qpdf.exe',
           'C:/laragon/bin/qpdf/qpdf.exe',
           'C:/Program Files/qpdf/bin/qpdf.exe']
        : [QPDF_PATH, '/usr/bin/qpdf', '/usr/local/bin/qpdf', '/opt/homebrew/bin/qpdf'];
    foreach ($candidates as $c) {
        if ($c !== '' && is_file($c)) {
            return PHP_OS_FAMILY === 'Windows' ? str_replace('/', '\\', $c) : $c;
        }
    }
    return 'qpdf';                                         // fall back to PATH
}

// ---------- Friendly error page (instead of a blank or frozen screen) ----------
// Shows a simple, clear error page and stops. htmlspecialchars() makes the text safe to print.
function pg_fail(string $title, string $message): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "$title: $message\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . $t . ' | ' . APP_NAME . '</title><style>body{font-family:system-ui,"Segoe UI",sans-serif;background:#f3f6f4;color:#17241c;display:grid;place-items:center;min-height:100vh;margin:0}'
       . '.b{background:#fff;border-radius:16px;padding:32px;max-width:520px;box-shadow:0 10px 30px rgba(16,40,24,.12)}h1{font-size:20px;margin:0 0 8px}p{color:#55645b;line-height:1.5}'
       . 'a{display:inline-block;margin-top:8px;background:#0b7a3e;color:#fff;padding:10px 18px;border-radius:10px;text-decoration:none}</style></head>'
       . '<body><div class="b"><h1>' . $t . '</h1><p>' . $m . '</p><a href="">Try again</a></div></body></html>';
    exit;
}

// Any error that is not handled anywhere else ends up here.
// The real error goes to the PHP error log (for the developer); the user only sees a clear message.
// A PDOException means MySQL could not be reached, so the message tells the user to start Laragon.
set_exception_handler(function (Throwable $ex): void {
    error_log('PesaGuard: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    if ($ex instanceof PDOException) {
        pg_fail('Database not available', 'PesaGuard could not reach MySQL. Open Laragon, click "Start All", then try again.');
    }
    pg_fail('Something went wrong', 'The page could not be completed. Please go back and try again.');
});

// ---------- Database connection ----------
// db() returns the database connection. "static" keeps the connection after the first call,
// so every query on the same page reuses one connection instead of opening a new one.
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $options = [
            // ERRMODE_EXCEPTION: any SQL error throws an exception, so nothing fails silently.
            // FETCH_ASSOC: each row comes back as an array keyed by column name, e.g. $row['email'].
            // EMULATE_PREPARES false: MySQL itself fills in the ? placeholders (true prepared statements).
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,           // never wait forever for the database
        ];
        // SQLite is used only by the automated tests; the real system always uses MySQL.
        if (DB_DRIVER === 'sqlite') {
            $pdo = new PDO('sqlite:' . SQLITE_PATH, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
    }
    return $pdo;
}

// ---------- Secure session ----------
// A session remembers who is logged in from page to page, using a cookie in the browser.
//  httponly: JavaScript cannot read the cookie, so injected scripts cannot steal it.
//  samesite Strict: the cookie is not sent when another website links or posts to PesaGuard (helps against CSRF).
//  secure: when the site runs on HTTPS, the cookie is only sent over HTTPS.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('PESAGUARD');
    session_start();
}

// Load the libraries installed with Composer: Smalot PdfParser (reads PDFs) and Dompdf (makes PDF reports).
require_once APP_ROOT . '/vendor/autoload.php';
// Autoloader: when the code says "new FraudEngine", PHP loads classes/FraudEngine.php by itself.
spl_autoload_register(function (string $class): void {
    $file = APP_ROOT . '/classes/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
// Load the shared helper functions (escaping, CSRF, login checks...).
require_once APP_ROOT . '/includes/functions.php';
