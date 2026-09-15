<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Pembelian';

$status = trim((string) ($_GET['status'] ?? ''));
$validStatuses = ['draft', 'confirmed', 'cancelled_draft'];

$sql = 'SELECT pu.id, pu.purchase_number, pu.status, pu.total_amount, pu.created_at, s.name AS supplier_name
        FROM purchases pu JOIN suppliers s ON s.id = pu.supplier_id WHERE 1=1';
$params = [];
if (in_array($status, $validStatuses, true)) {
    $sql .= ' AND pu.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY pu.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$statusLabels = ['draft' => 'Draft', 'confirmed' => 'Terkonfirmasi', 'cancelled_draft' => 'Draft Dibatalkan'];
$statusBadge = ['draft' => 'inactive', 'confirmed' => 'active', 'cancelled_draft' => 'inactive'];

require __DIR__ . '/../includes/header.php';
?>

<div class="btn-row" style="margin-bottom:16px; justify-content:space-between">
  <form method="get" class="search-bar">
    <select name="status" onchange="this.form.submit()">
      <option value="">Semua status</option>
      <?php foreach ($statusLabels as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <a class="btn" href="<?= APP_BASE_PATH ?>/purchases/create.php">+ Pembelian Baru</a>
</div>

<div class="card">
<table>
  <thead><tr><th>No. Pembelian</th><th>Supplier</th><th>Status</th><th class="text-right">Total</th><th>Tanggal</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($purchases as $p): ?>
    <tr>
      <td><?= e($p['purchase_number']) ?></td>
      <td><?= e($p['supplier_name']) ?></td>
      <td><span class="badge badge-<?= e($statusBadge[$p['status']]) ?>"><?= e($statusLabels[$p['status']]) ?></span></td>
      <td class="text-right"><?= rupiah($p['total_amount']) ?></td>
      <td class="text-muted"><?= e(fmt_date($p['created_at'])) ?></td>
      <td>
        <?php if ($p['status'] === 'draft'): ?>
          <a href="<?= APP_BASE_PATH ?>/purchases/edit.php?id=<?= (int) $p['id'] ?>">Lanjutkan</a>
        <?php else: ?>
          <a href="<?= APP_BASE_PATH ?>/purchases/view.php?id=<?= (int) $p['id'] ?>">Lihat</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$purchases): ?>
    <tr><td colspan="6" class="empty-state">Belum ada pembelian.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
