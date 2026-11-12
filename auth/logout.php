<?php
/**
 * Log out: records it in the activity log, empties the session,
 * deletes the session cookie in the browser and ends the session.
 */
require_once __DIR__ . '/../config/config.php';

if ($user = current_user()) {
    log_activity((int) $user['user_id'], 'Logged out');
}
// Forget everything stored for this user.
$_SESSION = [];
// Expire the session cookie in the browser.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
// Start a fresh, empty session only to carry the "You have logged out." message.
session_start();
flash('info', 'You have logged out.');
redirect('auth/login.php');
