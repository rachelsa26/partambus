<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Pengguna';

$users = $pdo->query('SELECT id, username, full_name, role, active FROM users ORDER BY full_name')->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="btn-row" style="margin-bottom:16px">
  <a class="btn" href="<?= APP_BASE_PATH ?>/users/create.php">+ Tambah Pengguna</a>
</div>

<div class="card">
<table>
  <thead>
    <tr><th>Nama</th><th>Username</th><th>Role</th><th>Status</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['full_name']) ?></td>
      <td><?= e($u['username']) ?></td>
      <td><span class="badge badge-<?= e($u['role']) ?>"><?= e($u['role']) ?></span></td>
      <td><span class="badge badge-<?= $u['active'] ? 'active' : 'inactive' ?>"><?= $u['active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
      <td><a href="<?= APP_BASE_PATH ?>/users/edit.php?id=<?= (int) $u['id'] ?>">Edit</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?>
    <tr><td colspan="5" class="empty-state">Belum ada pengguna.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
