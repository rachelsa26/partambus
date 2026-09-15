<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Riwayat Pergerakan Stok';

$productId = (int) ($_GET['product_id'] ?? 0);
$movementType = trim((string) ($_GET['type'] ?? ''));
$backUrl = $productId > 0 ? APP_BASE_PATH . '/products/edit.php?id=' . $productId : APP_BASE_PATH . '/inventory/index.php';
$backLabel = $productId > 0 ? 'Kembali ke Detail Produk' : 'Kembali ke Inventaris';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$validTypes = ['initial_stock', 'purchase', 'sale', 'sale_void', 'adjustment_in', 'adjustment_out'];

$sql = 'SELECT sm.*, p.code AS product_code, p.name AS product_name, p.base_unit_name, u.full_name AS actor_name
        FROM stock_movements sm
        JOIN products p ON p.id = sm.product_id
        JOIN users u ON u.id = sm.actor_user_id
        WHERE 1=1';
$params = [];

if ($productId > 0) {
    $sql .= ' AND sm.product_id = ?';
    $params[] = $productId;
}
if (in_array($movementType, $validTypes, true)) {
    $sql .= ' AND sm.movement_type = ?';
    $params[] = $movementType;
}
$sql .= ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT ? OFFSET ?';

$stmt = $pdo->prepare($sql);
$i = 1;
foreach ($params as $p) {
    $stmt->bindValue($i++, $p);
}
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i++, $offset, PDO::PARAM_INT);
$stmt->execute();
$movements = $stmt->fetchAll();

$productName = null;
if ($productId > 0) {
    $pStmt = $pdo->prepare('SELECT code, name FROM products WHERE id = ?');
    $pStmt->execute([$productId]);
    $prod = $pStmt->fetch();
    $productName = $prod ? $prod['code'] . ' — ' . $prod['name'] : null;
}

$typeLabels = [
    'initial_stock' => 'Stok Awal',
    'purchase' => 'Pembelian',
    'sale' => 'Penjualan',
    'sale_void' => 'Void Penjualan',
    'adjustment_in' => 'Penyesuaian Masuk',
    'adjustment_out' => 'Penyesuaian Keluar',
];

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <form method="get" class="search-bar">
    <?php if ($productId > 0): ?><input type="hidden" name="product_id" value="<?= $productId ?>"><?php endif; ?>
    <select name="type" onchange="this.form.submit()">
      <option value="">Semua tipe</option>
      <?php foreach ($typeLabels as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= $movementType === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($productName): ?>
      <span class="text-muted">Produk: <strong><?= e($productName) ?></strong></span>
      <a class="btn btn-secondary btn-small" href="movements.php<?= $movementType ? '?type=' . e($movementType) : '' ?>">Lihat semua produk</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
<table>
  <thead>
    <tr><th>Waktu</th><th>Produk</th><th>Tipe</th><th class="text-right">Perubahan</th><th class="text-right">Saldo</th><th>Referensi</th><th>Aktor</th><th>Alasan</th></tr>
  </thead>
  <tbody>
  <?php foreach ($movements as $m): ?>
    <tr>
      <td class="text-muted"><?= e(fmt_date($m['created_at'])) ?></td>
      <td><?= e($m['product_code']) ?> — <?= e($m['product_name']) ?></td>
      <td><?= e($typeLabels[$m['movement_type']] ?? $m['movement_type']) ?></td>
      <td class="text-right <?= $m['qty_delta_base'] >= 0 ? '' : 'text-danger' ?>">
        <?= $m['qty_delta_base'] >= 0 ? '+' : '' ?><?= (int) $m['qty_delta_base'] ?> <?= e($m['base_unit_name']) ?>
      </td>
      <td class="text-right"><?= (int) $m['balance_after_base'] ?></td>
      <td class="text-muted"><?= $m['reference_type'] ? e($m['reference_type']) . ' #' . (int) $m['reference_id'] : '-' ?></td>
      <td><?= e($m['actor_name']) ?></td>
      <td class="text-muted"><?= e((string) $m['reason']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$movements): ?>
    <tr><td colspan="8" class="empty-state">Belum ada pergerakan stok.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<div class="btn-row">
  <?php $qs = ['type' => $movementType ?: null, 'product_id' => $productId ?: null]; ?>
  <?php if ($page > 1): ?><a class="btn btn-secondary btn-small" href="?<?= http_build_query(array_filter($qs + ['page' => $page - 1])) ?>">&laquo; Sebelumnya</a><?php endif; ?>
  <?php if (count($movements) === $perPage): ?><a class="btn btn-secondary btn-small" href="?<?= http_build_query(array_filter($qs + ['page' => $page + 1])) ?>">Berikutnya &raquo;</a><?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
