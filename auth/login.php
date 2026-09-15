<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

if (current_user()) {
    redirect('/dashboard.php');
}

$error = null;

if (is_post()) {
    verify_csrf();
    $username = post('username');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } elseif (attempt_login($pdo, $username, $password)) {
        redirect('/dashboard.php');
    } else {
        $error = 'Username atau password salah, atau akun tidak aktif.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
  <div class="login-box">
    <h1><?= e(APP_NAME) ?></h1>
    <?php if ($error): ?>
      <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autofocus required>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn" style="width:100%" data-loading-message="Memeriksa akun...">Masuk</button>
    </form>
  </div>
</div>
<div class="loading-overlay hidden" id="global-loading-overlay" aria-live="polite" aria-busy="true">
  <div class="loading-box">
    <div class="loading-spinner" aria-hidden="true"></div>
    <p class="loading-message" data-loading-message-text>Memproses...</p>
  </div>
</div>
<script src="<?= APP_BASE_PATH ?>/assets/js/app.js"></script>
</body>
</html>
