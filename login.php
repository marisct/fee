<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect(app_url('index.php'));
}
try {
    $hasUsers = (int)db_val('SELECT COUNT(*) FROM users') > 0;
} catch (Throwable $e) {
    $hasUsers = false; // database or tables missing: run the installer
}
if (!$hasUsers) {
    redirect(app_url('install.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (attempt_login(post('email'), (string)($_POST['password'] ?? ''))) {
        $next = $_SESSION['after_login'] ?? '';
        unset($_SESSION['after_login']);
        redirect($next && str_starts_with($next, '/') ? $next : app_url('index.php'));
    }
    $error = 'Wrong email or password.';
}
$appName = (string)setting('institute_name', $CONFIG['app_name'] ?? 'Fee Manager');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - <?= e($appName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(app_url('assets/app.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="text-center mb-4">
      <div class="logo"><i class="bi bi-trophy-fill"></i></div>
      <h1>Login to <?= e($appName) ?></h1>
      <div class="text-secondary small">Fee Manager</div>
    </div>
    <?php foreach (take_flashes() as [$type, $msg]): ?><div class="alert alert-<?= e($type) ?> small"><?= e($msg) ?></div><?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required autofocus placeholder="you@example.com" value="<?= e(post('email')) ?>">
      </div>
      <div class="mb-4">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
      </div>
      <button class="btn btn-primary btn-lg w-100">Login</button>
    </form>
  </div>
</div>
</body>
</html>
