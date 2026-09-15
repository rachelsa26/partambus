<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Inventaris';

$lowStock = $pdo->query(
    'SELECT id, code, name, base_unit_name, current_stock_base, low_stock_threshold_base
     FROM products WHERE active = 1 AND current_stock_base <= low_stock_threshold_base
     ORDER BY current_stock_base ASC LIMIT 20'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card-grid">
  <a class="card" href="<?= APP_BASE_PATH ?>/inventory/initial_stock.php" style="text-decoration:none">
    <strong>Catat Stok Awal</strong>
    <p class="text-muted" style="margin-bottom:0">Input stok pertama kali untuk produk baru/lama, dengan atau tanpa biaya modal.</p>
  </a>
  <a class="card" href="<?= APP_BASE_PATH ?>/inventory/adjustment.php" style="text-decoration:none">
    <strong>Penyesuaian Stok</strong>
    <p class="text-muted" style="margin-bottom:0">Koreksi stok (barang hilang/rusak/ditemukan) dengan alasan wajib diisi.</p>
  </a>
  <a class="card" href="<?= APP_BASE_PATH ?>/inventory/movements.php" style="text-decoration:none">
    <strong>Riwayat Pergerakan Stok</strong>
    <p class="text-muted" style="margin-bottom:0">Ledger lengkap semua perubahan stok yang bisa ditelusuri.</p>
  </a>
</div>

<div class="card">
  <strong>Produk Stok Rendah/Habis</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Kode</th><th>Nama</th><th class="text-right">Stok</th><th class="text-right">Batas</th></tr></thead>
    <tbody>
    <?php foreach ($lowStock as $p): ?>
      <tr>
        <td><?= e($p['code']) ?></td>
        <td><?= e($p['name']) ?></td>
        <td class="text-right"><?= (int) $p['current_stock_base'] ?> <?= e($p['base_unit_name']) ?></td>
        <td class="text-right"><?= (int) $p['low_stock_threshold_base'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lowStock): ?>
      <tr><td colspan="4" class="empty-state">Tidak ada produk dengan stok rendah.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
