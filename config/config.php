<?php
/**
 * PesaGuard – M-Pesa Statement Analyzer and Fraud Detection System
 * Application configuration (Laragon: http://mpesa-analyzer.test)
 */

define('APP_NAME', 'PesaGuard');
define('APP_TAGLINE', 'M-Pesa Statement Analyzer & Fraud Detection System');
define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set('Africa/Nairobi');

// ---------- Database (MySQL on Laragon) ----------
define('DB_DRIVER', getenv('PG_DB_DRIVER') ?: 'mysql');   // 'sqlite' is used only by the automated tests
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'mpesa_analyzer_db');
define('DB_USER', 'root');
define('DB_PASS', '');                                     // Laragon default: empty password
define('SQLITE_PATH', getenv('PG_SQLITE_PATH') ?: APP_ROOT . '/storage/test.sqlite');

// ---------- Uploads ----------
define('UPLOAD_PATH', APP_ROOT . '/storage/tmp/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);              // 10 MB

// ---------- Fraud scoring bands ----------
define('MEDIUM_RISK', 30);
define('HIGH_RISK', 60);

// ---------- qpdf (decrypts password-protected statements) ----------
// Leave empty to auto-detect: the copy bundled in tools/qpdf, or qpdf placed in C:\laragon\bin\qpdf
define('QPDF_PATH', '');

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

set_exception_handler(function (Throwable $ex): void {
    error_log('PesaGuard: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    if ($ex instanceof PDOException) {
        pg_fail('Database not available', 'PesaGuard could not reach MySQL. Open Laragon, click "Start All", then try again.');
    }
    pg_fail('Something went wrong', 'The page could not be completed. Please go back and try again.');
});

// ---------- Database connection ----------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,           // never wait forever for the database
        ];
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

require_once APP_ROOT . '/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    $file = APP_ROOT . '/classes/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
require_once APP_ROOT . '/includes/functions.php';
