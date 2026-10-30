<?php
require_once __DIR__ . '/config/config.php';

redirect(current_user() ? 'dashboard.php' : 'auth/login.php');
