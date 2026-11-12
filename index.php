<?php
/**
 * Entry point:
 * dashboard when logged in, otherwise the login page.
 */
require_once __DIR__ . '/config/config.php';

redirect(current_user() ? 'dashboard.php' : 'auth/login.php');
