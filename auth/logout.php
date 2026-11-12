<?php
/**
 * Logout: audit entry, clear the session,
 * expire the cookie and destroy the session.
 */
require_once __DIR__ . '/../config/config.php';

if ($user = current_user()) {
    log_activity((int) $user['user_id'], 'Logged out');
}
// Clear session data
$_SESSION = [];
// Expire the session cookie
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
// New empty session for the logged-out message
session_start();
flash('info', 'You have logged out.');
redirect('auth/login.php');
