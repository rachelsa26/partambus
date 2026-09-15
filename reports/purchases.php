<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Laporan Pembelian';
$backUrl = APP_BASE_PATH . '/reports/index.php';
$backLabel = 'Kembali ke Laporan';

$range = resolve_date_range((string) ($_GET['preset'] ?? 'month'), (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));

$totalStmt = $pdo->prepare(
    'SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total
     FROM purchases WHERE status = "confirmed" AND confirmed_at BETWEEN ? AND ?'
);
$totalStmt->execute([$range['from'], $range['to']]);
$totals = $totalStmt->fetch();

$bySupplierStmt = $pdo->prepare(
    'SELECT s.name AS supplier_name, COUNT(*) AS cnt, COALESCE(SUM(p.total_amount),0) AS total
     FROM purchases p JOIN suppliers s ON s.id = p.supplier_id
     WHERE p.status = "confirmed" AND p.confirmed_at BETWEEN ? AND ?
     GROUP BY s.id, s.name ORDER BY total DESC'
);
$bySupplierStmt->execute([$range['from'], $range['to']]);
$bySupplier = $bySupplierStmt->fetchAll();

$lastPriceStmt = $pdo->query(
    'SELECT product_id, code, name, unit_name_snapshot, unit_cost, cost_per_base, confirmed_at, supplier_name
     FROM (
       SELECT pi.product_id, p.code, p.name, pi.unit_name_snapshot, pi.unit_cost, pi.cost_per_base, pu.confirmed_at, s.name AS supplier_name,
              ROW_NUMBER() OVER (PARTITION BY pi.product_id ORDER BY pu.confirmed_at DESC) AS rn
       FROM purchase_items pi
       JOIN purchases pu ON pu.id = pi.purchase_id AND pu.status = "confirmed"
       JOIN products p ON p.id = pi.product_id
       JOIN suppliers s ON s.id = pu.supplier_id
     ) ranked
     WHERE rn = 1
     ORDER BY name'
);
$lastPrices = $lastPriceStmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <?php require __DIR__ . '/../includes/date_range_filter.php'; ?>
</div>

<div class="card-grid">
  <div class="card">
    <div class="text-muted">Total Pembelian</div>
    <div style="font-size:22px;font-weight:700"><?= rupiah($totals['total']) ?></div>
  </div>
  <div class="card">
    <div class="text-muted">Jumlah Transaksi Beli</div>
    <div style="font-size:22px;font-weight:700"><?= (int) $totals['cnt'] ?></div>
  </div>
</div>

<div class="card">
  <strong>Berdasarkan Supplier</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Supplier</th><th class="text-right">Jumlah Transaksi</th><th class="text-right">Total</th></tr></thead>
    <tbody>
    <?php foreach ($bySupplier as $s): ?>
      <tr><td><?= e($s['supplier_name']) ?></td><td class="text-right"><?= (int) $s['cnt'] ?></td><td class="text-right"><?= rupiah($s['total']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$bySupplier): ?><tr><td colspan="3" class="empty-state">Tidak ada pembelian di periode ini.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <strong>Harga Beli Terakhir per Produk (seluruh riwayat)</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Produk</th><th>Supplier Terakhir</th><th>Satuan</th><th class="text-right">Biaya/Unit</th><th class="text-right">Biaya/Satuan Dasar</th><th>Tanggal</th></tr></thead>
    <tbody>
    <?php foreach ($lastPrices as $lp): ?>
      <tr>
        <td><?= e($lp['code']) ?> — <?= e($lp['name']) ?></td>
        <td><?= e($lp['supplier_name']) ?></td>
        <td><?= e($lp['unit_name_snapshot']) ?></td>
        <td class="text-right"><?= rupiah($lp['unit_cost']) ?></td>
        <td class="text-right"><?= rupiah($lp['cost_per_base']) ?></td>
        <td class="text-muted"><?= e($lp['confirmed_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lastPrices): ?><tr><td colspan="6" class="empty-state">Belum ada pembelian terkonfirmasi.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="form-hint">Ini bersifat informasi harga terakhir, bukan sumber HPP historis (HPP tetap dari snapshot harga modal saat penjualan).</p>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
