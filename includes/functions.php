<?php
/**
 * Shared helper functions: output escaping, CSRF, authentication guards,
 * flash messages, activity logging and SQL helpers that work on MySQL and SQLite.
 */

// ---------- Base URL (works on a Laragon virtual host or in a sub-folder) ----------
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

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

// ---------- Output ----------
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float|string|null $amount): string
{
    return 'KES ' . number_format((float) $amount, 2);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

// ---------- CSRF protection ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session has expired. Please go back, refresh the page and try again.');
    }
}

// ---------- Flash messages ----------
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

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('warning', 'Please log in to continue.');
        redirect('auth/login.php');
    }
    return $user;
}

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

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'Admin';
}

// ---------- Activity log ----------
function log_activity(?int $userId, string $action): void
{
    $st = db()->prepare("INSERT INTO activity_log (user_id, action, ip_address, created_at) VALUES (?, ?, ?, ?)");
    $st->execute([$userId, mb_substr($action, 0, 255), $_SERVER['REMOTE_ADDR'] ?? 'cli', now()]);
}

// ---------- SQL helpers (MySQL in production, SQLite in automated tests) ----------
function sql_month(string $col): string
{
    return DB_DRIVER === 'sqlite' ? "strftime('%Y-%m', $col)" : "DATE_FORMAT($col, '%Y-%m')";
}

function sql_hour(string $col): string
{
    return DB_DRIVER === 'sqlite' ? "CAST(strftime('%H', $col) AS INTEGER)" : "HOUR($col)";
}

// ---------- Misc ----------
function risk_badge(string $level): string
{
    $cls = ['High' => 'danger', 'Medium' => 'warning text-dark', 'Low' => 'success'][$level] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . e($level) . '</span>';
}

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

/** Builds the WHERE fragment that limits transactions to what the user may see. */
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
