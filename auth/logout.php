<?php
require_once __DIR__ . '/../config/config.php';

if ($user = current_user()) {
    log_activity((int) $user['user_id'], 'Logged out');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
session_start();
flash('info', 'You have logged out.');
redirect('auth/login.php');
