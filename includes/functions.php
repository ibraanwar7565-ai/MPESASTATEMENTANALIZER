<?php
/**
 * functions.php: small helper functions used by every page.
 *
 * Sections, top to bottom:
 *  - URLs and redirects
 *  - Output: e() makes text safe to print, money() formats KES amounts
 *  - CSRF protection: a secret token in every form
 *  - Flash messages: one-time messages shown on the next page
 *  - Authentication: who is logged in, and the page guards for users and admins
 *  - Activity log
 *  - SQL helpers that work on both MySQL and SQLite (used by the tests)
 *  - Badges, and access control: each user sees only their own data
 */

// ---------- Base URL (works on a Laragon virtual host or in a sub-folder) ----------
// Works out the folder the system lives in: "/mpesa-analyzer" on http://localhost/mpesa-analyzer,
// or "" on http://mpesa-analyzer.test. Every link is built from it, so links work in both cases.
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

// Builds a link inside the system, e.g. url('flagged.php') gives /mpesa-analyzer/flagged.php
function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

// Sends the browser to another page and stops this script. "never" means the function never returns.
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

// ---------- Output ----------
// e() = escape. Turns characters such as < > " & into safe codes before printing data,
// so text typed by a user (or found in a statement) can never run as HTML or JavaScript (XSS protection).
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Formats an amount as Kenya shillings, e.g. 1234.5 becomes "KES 1,234.50".
function money(float|string|null $amount): string
{
    return 'KES ' . number_format((float) $amount, 2);
}

// The current date and time in the format MySQL stores, e.g. 2026-10-15 14:00:00
function now(): string
{
    return date('Y-m-d H:i:s');
}

// ---------- CSRF protection ----------
// CSRF = Cross-Site Request Forgery: another website secretly submitting a form using your logged-in session.
// Defence: each session gets a random secret token. Every form sends it back, and verify_csrf() checks it.
// A fake form on another site cannot know the token, so its request is rejected.
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// The hidden <input> that carries the token. Every form in the system prints this.
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

// Called at the start of every form handler. hash_equals() compares the two tokens in constant time,
// so an attacker cannot guess the token by measuring how long the comparison takes.
function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session has expired. Please go back, refresh the page and try again.');
    }
}

// ---------- Flash messages ----------
// Flash message: saved in the session now, shown once on the next page (e.g. "Category updated."), then removed.
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
// Returns the logged-in user (a row from the users table), or null if nobody is logged in.
// "static" means the database is asked only once per page. A deactivated account is logged out at once.
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

// Page guard: placed at the top of every page that needs a logged-in user. Visitors are sent to the login page.
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('warning', 'Please log in to continue.');
        redirect('auth/login.php');
    }
    return $user;
}

// Page guard for admin pages: a normal user gets "Access denied" (HTTP 403) and is sent to the dashboard.
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

// True when the logged-in user is an administrator.
function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'Admin';
}

// ---------- Activity log ----------
// Writes one line to the activity_log table: who did what, from which IP address, and when.
// Used for logins, failed logins (the lockout counts these), uploads, reviews and every admin change.
function log_activity(?int $userId, string $action): void
{
    $st = db()->prepare("INSERT INTO activity_log (user_id, action, ip_address, created_at) VALUES (?, ?, ?, ?)");
    $st->execute([$userId, mb_substr($action, 0, 255), $_SERVER['REMOTE_ADDR'] ?? 'cli', now()]);
}

// ---------- SQL helpers (MySQL in production, SQLite in automated tests) ----------
// MySQL and SQLite name their date functions differently. These helpers return the right SQL for each,
// so the same reports work on MySQL (the real system) and SQLite (the automated tests).
// sql_month: groups a date by month, e.g. 2026-10-15 becomes "2026-10".
function sql_month(string $col): string
{
    return DB_DRIVER === 'sqlite' ? "strftime('%Y-%m', $col)" : "DATE_FORMAT($col, '%Y-%m')";
}

// sql_hour: the hour of a date and time, 0 to 23.
function sql_hour(string $col): string
{
    return DB_DRIVER === 'sqlite' ? "CAST(strftime('%H', $col) AS INTEGER)" : "HOUR($col)";
}

// ---------- Misc ----------
// Coloured label for a risk level: Low = green, Medium = amber, High = red.
function risk_badge(string $level): string
{
    $cls = ['High' => 'danger', 'Medium' => 'warning text-dark', 'Low' => 'success'][$level] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . e($level) . '</span>';
}

// Coloured label for a review or account status: Pending, Confirmed, Dismissed, Active, Inactive.
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
 * Access control. Builds the extra WHERE conditions that limit a query to what this user may see:
 *  - a normal user: only rows from their own statements ("s.user_id = ?")
 *  - an administrator: everything
 *  - if one statement is chosen: only that statement
 * The values go into $params and are filled in through ? placeholders, never pasted into the SQL.
 * Example result for a normal user: " AND s.user_id = ? AND s.statement_id = ?"
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
