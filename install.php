<?php
/**
 * One-click installer: creates the mpesa_analyzer_db database, the tables and the seed data
 * from database/mpesa_analyzer_db.sql. Runs only from the local machine and only once.
 */
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1'], true);
$messages = [];
$ok = false;

if (!$local) {
    http_response_code(403);
    exit('The installer can only be run on the local server.');
}

require_once __DIR__ . '/config/config.php';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote(DB_NAME) . " AND table_name = 'users'")->fetchColumn();
    if ($exists) {
        $messages[] = ['info', 'PesaGuard is already installed. To start again, drop the database "' . DB_NAME . '" in HeidiSQL and reload this page.'];
        $ok = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sql = file_get_contents(__DIR__ . '/database/mpesa_analyzer_db.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        $messages[] = ['success', 'Database "' . DB_NAME . '" created with all tables and default data.'];
        $ok = true;
    }
} catch (PDOException $ex) {
    $messages[] = ['danger', 'Could not connect to MySQL: ' . $ex->getMessage() . '. Start MySQL in Laragon ("Start All") and try again.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="guest">
<div class="auth-wrap">
    <div class="card auth-card p-4">
        <h1 class="h4 fw-bold mb-1"><?= e(APP_NAME) ?> installer</h1>
        <p class="text-muted small"><?= e(APP_TAGLINE) ?></p>
        <?php foreach ($messages as [$type, $msg]): ?>
            <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
        <?php endforeach; ?>
        <?php if ($ok): ?>
            <p class="small mb-2">Default accounts:</p>
            <ul class="small">
                <li>Administrator: <code>admin@pesaguard.test</code> / <code>Admin@123</code></li>
                <li>User: <code>brian@example.com</code> / <code>Demo@123</code></li>
            </ul>
            <a href="auth/login.php" class="btn btn-pg w-100">Go to login</a>
        <?php else: ?>
            <p>This will create the MySQL database <code><?= e(DB_NAME) ?></code> on <code><?= e(DB_HOST) ?></code> with the tables and default data.</p>
            <form method="post">
                <button class="btn btn-pg w-100">Install now</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
