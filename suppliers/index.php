<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Supplier';

$search = trim((string) ($_GET['q'] ?? ''));
$showInactive = isset($_GET['show_inactive']);

$sql = 'SELECT id, name, contact_person, phone, active FROM suppliers WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND name LIKE ?';
    $params[] = '%' . $search . '%';
}
if (!$showInactive) {
    $sql .= ' AND active = 1';
}
$sql .= ' ORDER BY name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="btn-row" style="margin-bottom:16px; justify-content:space-between">
  <form method="get" class="search-bar">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cari nama supplier...">
    <label class="checkbox-inline">
      <input type="checkbox" name="show_inactive" value="1" onchange="this.form.submit()" <?= $showInactive ? 'checked' : '' ?>>
      Tampilkan nonaktif
    </label>
    <button type="submit" class="btn btn-secondary">Cari</button>
  </form>
  <a class="btn" href="<?= APP_BASE_PATH ?>/suppliers/create.php">+ Tambah Supplier</a>
</div>

<div class="card">
<table>
  <thead><tr><th>Nama</th><th>Kontak</th><th>Telepon</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($suppliers as $s): ?>
    <tr>
      <td><?= e($s['name']) ?></td>
      <td><?= e((string) $s['contact_person']) ?></td>
      <td><?= e((string) $s['phone']) ?></td>
      <td><span class="badge badge-<?= $s['active'] ? 'active' : 'inactive' ?>"><?= $s['active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
      <td><a href="<?= APP_BASE_PATH ?>/suppliers/edit.php?id=<?= (int) $s['id'] ?>">Edit</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$suppliers): ?>
    <tr><td colspan="5" class="empty-state">Belum ada supplier.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
