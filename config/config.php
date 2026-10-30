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
function qpdf_bin(): string
{
    $candidates = PHP_OS_FAMILY === 'Windows'
        ? [APP_ROOT . '/tools/qpdf/bin/qpdf.exe',           // bundled with the project
           'C:/laragon/bin/qpdf/bin/qpdf.exe',
           'C:/Program Files/qpdf/bin/qpdf.exe']
        : ['/usr/bin/qpdf', '/usr/local/bin/qpdf', '/opt/homebrew/bin/qpdf'];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return PHP_OS_FAMILY === 'Windows' ? str_replace('/', '\\', $c) : $c;
        }
    }
    return 'qpdf';                                         // fall back to PATH
}

// ---------- Database connection ----------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
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
