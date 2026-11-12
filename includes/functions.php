<?php
/**
 * Shared helper functions.
 *
 * Sections:
 *  - URLs and redirects
 *  - Output escaping and money formatting
 *  - CSRF tokens
 *  - Flash messages
 *  - Authentication and page guards
 *  - Activity log
 *  - SQL helpers (MySQL / SQLite)
 *  - Badges and data-access scope
 */

// ---------- Base URL (works on a Laragon virtual host or in a sub-folder) ----------
// Base path: "/mpesa-analyzer" under localhost, "" on a virtual host
// such as http://mpesa-analyzer.test. All links are built from it.
function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $base = '';
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $appRoot = realpath(APP_ROOT);
        if ($docRoot && $appRoot && stripos($appRoot, $docRoot) === 0) {
            $base = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
            $base = rtrim($base, '/');
        }
    }
    return $base;
}

// url('flagged.php') -> /mpesa-analyzer/flagged.php
function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

// Redirect and stop
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

// ---------- Output ----------
// Escape for HTML output (XSS protection).
// Used on every value printed in a page.
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// 1234.5 -> "KES 1,234.50"
function money(float|string|null $amount): string
{
    return 'KES ' . number_format((float) $amount, 2);
}

// MySQL DATETIME format
function now(): string
{
    return date('Y-m-d H:i:s');
}

// ---------- CSRF protection ----------
// CSRF protection: one random token per session, sent with every form
// and checked by verify_csrf() before any POST is processed.
// A form posted from another site cannot know the token.
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Hidden input carrying the token
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

// Called first in every POST handler. hash_equals() is a constant-time
// comparison (avoids timing attacks).
function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session has expired. Please go back, refresh the page and try again.');
    }
}

// ---------- Flash messages ----------
// One-time message shown on the next page
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------- Authentication ----------
// Logged-in user row, or null. Cached for the request;
// an account set to Inactive is logged out immediately.
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $st = db()->prepare("SELECT user_id, full_name, email, phone, role, status FROM users WHERE user_id = ?");
            $st->execute([$_SESSION['user_id']]);
            $user = $st->fetch() ?: null;
            if ($user && $user['status'] !== 'Active') {
                $user = null;
                session_destroy();
            }
        }
    }
    return $user;
}

// Page guard: redirects visitors to the login page
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('warning', 'Please log in to continue.');
        redirect('auth/login.php');
    }
    return $user;
}

// Admin page guard: 403 and redirect for non-admins
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'Admin') {
        http_response_code(403);
        flash('danger', 'Access denied. That page is for administrators only.');
        redirect('dashboard.php');
    }
    return $user;
}

// True for administrators
function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'Admin';
}

// ---------- Activity log ----------
// Audit trail entry: user, action, IP address, time.
// Failed logins are logged too; the login lockout counts them.
function log_activity(?int $userId, string $action): void
{
    $st = db()->prepare("INSERT INTO activity_log (user_id, action, ip_address, created_at) VALUES (?, ?, ?, ?)");
    $st->execute([$userId, mb_substr($action, 0, 255), $_SERVER['REMOTE_ADDR'] ?? 'cli', now()]);
}

// ---------- SQL helpers (MySQL in production, SQLite in automated tests) ----------
// Date functions differ between MySQL and SQLite (tests),
// so these return the right SQL for the active driver.
// sql_month: 2026-10-15 -> "2026-10"
function sql_month(string $col): string
{
    return DB_DRIVER === 'sqlite' ? "strftime('%Y-%m', $col)" : "DATE_FORMAT($col, '%Y-%m')";
}

// sql_hour: hour of day, 0-23
function sql_hour(string $col): string
{
    return DB_DRIVER === 'sqlite' ? "CAST(strftime('%H', $col) AS INTEGER)" : "HOUR($col)";
}

// ---------- Misc ----------
// Low = green, Medium = amber, High = red
function risk_badge(string $level): string
{
    $cls = ['High' => 'danger', 'Medium' => 'warning text-dark', 'Low' => 'success'][$level] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . e($level) . '</span>';
}

// Review / account status badge
function status_badge(string $status): string
{
    $cls = ['Pending' => 'warning text-dark', 'Confirmed' => 'danger', 'Dismissed' => 'secondary',
            'Active' => 'success', 'Inactive' => 'secondary'][$status] ?? 'light text-dark';
    return '<span class="badge bg-' . $cls . '">' . e($status) . '</span>';
}

/** Statements that belong to the logged-in user (admins see every statement). */
function user_statements(array $user): array
{
    if ($user['role'] === 'Admin') {
        return db()->query("SELECT s.*, u.full_name FROM statements s JOIN users u ON u.user_id = s.user_id
                            ORDER BY s.uploaded_at DESC")->fetchAll();
    }
    $st = db()->prepare("SELECT s.*, ? AS full_name FROM statements s WHERE s.user_id = ? ORDER BY s.uploaded_at DESC");
    $st->execute([$user['full_name'], $user['user_id']]);
    return $st->fetchAll();
}

/**
 * Access control: extra WHERE conditions for the current user.
 *  - normal user: own statements only ("s.user_id = ?")
 *  - administrator: no restriction
 *  - optional: a single statement
 * Values are appended to $params and bound as ? placeholders.
 * Example: " AND s.user_id = ? AND s.statement_id = ?"
 */
function scope_sql(array $user, ?int $statementId, array &$params, string $alias = 's'): string
{
    $where = [];
    if ($user['role'] !== 'Admin') {
        $where[] = "$alias.user_id = ?";
        $params[] = $user['user_id'];
    }
    if ($statementId) {
        $where[] = "$alias.statement_id = ?";
        $params[] = $statementId;
    }
    return $where ? ' AND ' . implode(' AND ', $where) : '';
}
