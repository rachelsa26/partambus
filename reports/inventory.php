<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Laporan Inventaris';
$backUrl = APP_BASE_PATH . '/reports/index.php';
$backLabel = 'Kembali ke Laporan';

$products = $pdo->query(
    'SELECT id, code, name, base_unit_name, current_stock_base, low_stock_threshold_base, current_wac
     FROM products WHERE active = 1 ORDER BY name'
)->fetchAll();

$totalValuation = 0.0;
$unknownCostCount = 0;
$lowStockCount = 0;
$outOfStockCount = 0;

foreach ($products as $p) {
    if ($p['current_wac'] !== null) {
        $totalValuation += (int) $p['current_stock_base'] * (float) $p['current_wac'];
    } elseif ((int) $p['current_stock_base'] > 0) {
        $unknownCostCount++;
    }
    if ((int) $p['current_stock_base'] <= 0) {
        $outOfStockCount++;
    } elseif ((int) $p['current_stock_base'] <= (int) $p['low_stock_threshold_base']) {
        $lowStockCount++;
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card-grid">
  <div class="card">
    <div class="text-muted">Nilai Persediaan (Harga Modal)</div>
    <div style="font-size:22px;font-weight:700"><?= rupiah($totalValuation) ?></div>
    <?php if ($unknownCostCount > 0): ?>
      <p class="form-hint"><?= $unknownCostCount ?> produk dengan stok tapi biaya modal belum diketahui, tidak termasuk nilai di atas.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="text-muted">Stok Rendah</div>
    <div style="font-size:22px;font-weight:700"><?= $lowStockCount ?></div>
  </div>
  <div class="card">
    <div class="text-muted">Stok Habis</div>
    <div style="font-size:22px;font-weight:700"><?= $outOfStockCount ?></div>
  </div>
</div>

<div class="card">
  <strong>Semua Produk Aktif</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Kode</th><th>Nama</th><th class="text-right">Stok</th><th class="text-right">Batas Rendah</th><th class="text-right">Harga Modal</th><th class="text-right">Nilai</th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
      <?php
        $stock = (int) $p['current_stock_base'];
        $isOut = $stock <= 0;
        $isLow = !$isOut && $stock <= (int) $p['low_stock_threshold_base'];
        $valuation = $p['current_wac'] !== null ? $stock * (float) $p['current_wac'] : null;
      ?>
      <tr>
        <td><?= e($p['code']) ?></td>
        <td><?= e($p['name']) ?></td>
        <td class="text-right">
          <?= $stock ?> <?= e($p['base_unit_name']) ?>
          <?php if ($isOut): ?><span class="badge badge-low-stock">habis</span><?php elseif ($isLow): ?><span class="badge badge-low-stock">rendah</span><?php endif; ?>
        </td>
        <td class="text-right"><?= (int) $p['low_stock_threshold_base'] ?></td>
        <td class="text-right"><?= $p['current_wac'] !== null ? rupiah($p['current_wac']) : '<span class="text-muted">?</span>' ?></td>
        <td class="text-right"><?= $valuation !== null ? rupiah($valuation) : '<span class="text-muted">?</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$products): ?>
      <tr><td colspan="6" class="empty-state">Belum ada produk aktif.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
