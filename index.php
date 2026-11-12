<?php
/**
 * Home address (http://localhost/mpesa-analyzer/):
 * logged-in users go to the dashboard, everyone else to the login page.
 */
require_once __DIR__ . '/config/config.php';

redirect(current_user() ? 'dashboard.php' : 'auth/login.php');
