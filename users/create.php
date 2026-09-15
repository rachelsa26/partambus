<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Tambah Pengguna';
$backUrl = APP_BASE_PATH . '/users/index.php';
$backLabel = 'Kembali ke Daftar Pengguna';

$errors = [];
$form = ['username' => '', 'full_name' => '', 'role' => 'cashier'];

if (is_post()) {
    verify_csrf();
    $form['username'] = post('username');
    $form['full_name'] = post('full_name');
    $form['role'] = post('role');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if ($form['username'] === '') {
        $errors[] = 'Username wajib diisi.';
    }
    if ($form['full_name'] === '') {
        $errors[] = 'Nama lengkap wajib diisi.';
    }
    if (!in_array($form['role'], ['owner', 'cashier'], true)) {
        $errors[] = 'Role tidak valid.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password minimal 8 karakter.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$form['username']]);
        if ($check->fetch()) {
            $errors[] = 'Username sudah digunakan.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, role, active) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $form['username'],
            password_hash($password, PASSWORD_DEFAULT),
            $form['full_name'],
            $form['role'],
        ]);
        $newId = (int) $pdo->lastInsertId();

        log_audit($pdo, 'user_created', 'user', $newId, null, [
            'username' => $form['username'],
            'full_name' => $form['full_name'],
            'role' => $form['role'],
        ]);

        flash_set('success', 'Pengguna "' . $form['full_name'] . '" berhasil dibuat.');
        redirect('/users/index.php');
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:480px">
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-group">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= e($form['username']) ?>" required autofocus>
  </div>
  <div class="form-group">
    <label for="full_name">Nama Lengkap</label>
    <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" required>
  </div>
  <div class="form-group">
    <label for="role">Role</label>
    <select id="role" name="role">
      <option value="cashier" <?= $form['role'] === 'cashier' ? 'selected' : '' ?>>Kasir</option>
      <option value="owner" <?= $form['role'] === 'owner' ? 'selected' : '' ?>>Owner</option>
    </select>
  </div>
  <div class="form-row">
    <div class="form-group">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required minlength="8">
    </div>
    <div class="form-group">
      <label for="password_confirm">Konfirmasi Password</label>
      <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
    </div>
  </div>
  <div class="btn-row">
    <button type="submit" class="btn">Simpan</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/users/index.php">Batal</a>
  </div>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
