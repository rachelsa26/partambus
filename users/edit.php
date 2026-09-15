<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actingUser = require_role(['owner']);
$pageTitle = 'Edit Pengguna';
$backUrl = APP_BASE_PATH . '/users/index.php';
$backLabel = 'Kembali ke Daftar Pengguna';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, username, full_name, role, active FROM users WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    flash_set('error', 'Pengguna tidak ditemukan.');
    redirect('/users/index.php');
}

$errors = [];
$isSelf = $target['id'] === $actingUser['id'];

if (is_post()) {
    verify_csrf();
    $fullName = post('full_name');
    $role = post('role');
    $active = isset($_POST['active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if ($fullName === '') {
        $errors[] = 'Nama lengkap wajib diisi.';
    }
    if (!in_array($role, ['owner', 'cashier'], true)) {
        $errors[] = 'Role tidak valid.';
    }
    if ($isSelf && $active === 0) {
        $errors[] = 'Anda tidak bisa menonaktifkan akun Anda sendiri.';
    }
    if ($password !== '' && strlen($password) < 8) {
        $errors[] = 'Password baru minimal 8 karakter.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    }

    if (!$errors && ($role !== 'owner' || $active !== 1)) {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = "owner" AND active = 1 AND id != ?');
        $countStmt->execute([$target['id']]);
        $otherActiveOwners = (int) $countStmt->fetchColumn();

        if ($otherActiveOwners === 0 && $target['role'] === 'owner' && (int) $target['active'] === 1) {
            $errors[] = 'Tidak bisa mengubah role/menonaktifkan Owner aktif terakhir. Buat/aktifkan Owner lain terlebih dahulu.';
        }
    }

    if (!$errors) {
        $before = ['full_name' => $target['full_name'], 'role' => $target['role'], 'active' => (int) $target['active'], 'password_reset' => false];

        if ($password !== '') {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, role = ?, active = ?, password_hash = ? WHERE id = ?');
            $stmt->execute([$fullName, $role, $active, password_hash($password, PASSWORD_DEFAULT), $target['id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, role = ?, active = ? WHERE id = ?');
            $stmt->execute([$fullName, $role, $active, $target['id']]);
        }

        $afterForAudit = [
            'full_name' => $fullName,
            'role' => $role,
            'active' => $active,
            'password_reset' => $password !== '',
        ];
        if (audit_has_real_change($before, $afterForAudit)) {
            log_audit($pdo, 'user_updated', 'user', $target['id'], $before, $afterForAudit);
        }

        if ($isSelf) {
            $_SESSION['user']['full_name'] = $fullName;
            $_SESSION['user']['role'] = $role;
        }

        flash_set('success', 'Pengguna berhasil diperbarui.');
        redirect('/users/index.php');
    }

    $target = ['id' => $target['id'], 'username' => $target['username'], 'full_name' => $fullName, 'role' => $role, 'active' => $active];
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
    <label>Username</label>
    <input type="text" value="<?= e($target['username']) ?>" disabled>
  </div>
  <div class="form-group">
    <label for="full_name">Nama Lengkap</label>
    <input type="text" id="full_name" name="full_name" value="<?= e($target['full_name']) ?>" required>
  </div>
  <div class="form-group">
    <label for="role">Role</label>
    <select id="role" name="role" <?= $isSelf ? 'disabled' : '' ?>>
      <option value="cashier" <?= $target['role'] === 'cashier' ? 'selected' : '' ?>>Kasir</option>
      <option value="owner" <?= $target['role'] === 'owner' ? 'selected' : '' ?>>Owner</option>
    </select>
    <?php if ($isSelf): ?>
      <input type="hidden" name="role" value="<?= e($target['role']) ?>">
      <p class="form-hint">Anda tidak bisa mengubah role akun sendiri.</p>
    <?php endif; ?>
  </div>
  <div class="form-group">
    <label class="checkbox-inline">
      <input type="checkbox" name="active" value="1" <?= (int) $target['active'] === 1 ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
      Akun aktif
    </label>
    <?php if ($isSelf): ?>
      <input type="hidden" name="active" value="1">
    <?php endif; ?>
  </div>
  <div class="form-row">
    <div class="form-group">
      <label for="password">Password Baru (opsional)</label>
      <input type="password" id="password" name="password" minlength="8">
    </div>
    <div class="form-group">
      <label for="password_confirm">Konfirmasi Password Baru</label>
      <input type="password" id="password_confirm" name="password_confirm" minlength="8">
    </div>
  </div>
  <div class="btn-row">
    <button type="submit" class="btn">Simpan</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/users/index.php">Batal</a>
  </div>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
