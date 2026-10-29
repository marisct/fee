<?php
/**
 * One-time installer: creates the database tables and the first admin user.
 * Delete this file after installation (it also refuses to run once an admin exists).
 */
require __DIR__ . '/includes/bootstrap.php';

$installed = false;
try {
    $installed = (int)db_val('SELECT COUNT(*) FROM users') > 0;
} catch (Throwable $e) {
    $installed = false; // tables (or the database) do not exist yet
}

$error = '';
if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name  = post('name');
    $email = strtolower(post('email'));
    $pass  = (string)($_POST['password'] ?? '');
    $inst  = post('institute_name');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
        $error = 'Please fill all fields. Password must be at least 6 characters.';
    } else {
        try {
            // 1) Connect. On shared hosting (Hostinger, cPanel) the database is created in the
            //    hosting panel first; on a local XAMPP we try to create it when it is missing.
            try {
                db();
            } catch (PDOException $e) {
                $c   = $CONFIG['db'];
                $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306));
                try {
                    $pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $dbName = preg_replace('/[^A-Za-z0-9_]/', '', $c['name']);
                    $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $dbName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                } catch (PDOException $e2) {
                    throw new RuntimeException('Could not connect to the database. Check the db settings in config.php (host, name, user, password) and make sure the database exists in your hosting panel. MySQL said: ' . $e->getMessage());
                }
                db();
            }

            // 2) Run database.sql statement by statement
            $sql = file_get_contents(APP_ROOT . '/database.sql');
            if ($sql === false) {
                throw new RuntimeException('database.sql not found');
            }
            foreach (preg_split('/;\s*\r?\n/', $sql) as $stmt) {
                $stmt = trim((string)preg_replace('/^\s*--.*$/m', '', $stmt)); // drop comment lines
                if ($stmt === '') {
                    continue;
                }
                db()->exec($stmt);
            }

            // 3) First admin + basic settings
            db_insert('users', [
                'name'          => $name,
                'email'         => $email,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'role'          => 'admin',
            ]);
            if ($inst !== '') {
                set_setting('institute_name', $inst);
            }
            set_setting('wa_verify_token', random_token(12));
            set_setting('cron_secret', random_token(12));

            flash('success', 'Installation complete. Please log in, then open Settings to add your Razorpay and WhatsApp details.');
            redirect(app_url('login.php'));
        } catch (Throwable $e) {
            $error = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install - Fee Manager</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(app_url('assets/app.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
  <div class="auth-card" style="max-width: 460px;">
    <div>
      <div class="logo"><i class="bi bi-trophy-fill"></i></div>
      <h1 class="mb-1">Boxing Fee Manager setup</h1>
      <?php if ($installed): ?>
        <p class="text-secondary">The app is already installed.</p>
        <a class="btn btn-primary" href="login.php">Go to login</a>
        <p class="small text-secondary mt-3 mb-0">For safety, delete <code>install.php</code> from the server.</p>
      <?php else: ?>
        <p class="text-secondary small">This creates the database tables and your admin login. Database connection details come from <code>config.php</code>.</p>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Academy / club name</label>
            <input name="institute_name" class="form-control" value="<?= e(post('institute_name', 'Boxing Academy')) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Your name</label>
            <input name="name" class="form-control" required value="<?= e(post('name')) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Admin email (login)</label>
            <input type="email" name="email" class="form-control" required value="<?= e(post('email')) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Password (min 6 characters)</label>
            <input type="password" name="password" class="form-control" required minlength="6">
          </div>
          <button class="btn btn-primary btn-lg w-100">Install</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
