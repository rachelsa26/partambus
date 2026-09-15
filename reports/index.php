<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Laporan';

require __DIR__ . '/../includes/header.php';
?>

<div class="card-grid">
  <a class="card" href="<?= APP_BASE_PATH ?>/reports/sales.php" style="text-decoration:none">
    <strong>Penjualan &amp; Gross Profit</strong>
    <p class="text-muted" style="margin-bottom:0">Omzet, jumlah transaksi, HPP, gross profit, dan rincian metode pembayaran per periode.</p>
  </a>
  <a class="card" href="<?= APP_BASE_PATH ?>/reports/products.php" style="text-decoration:none">
    <strong>Performa Produk</strong>
    <p class="text-muted" style="margin-bottom:0">Produk paling laku, paling menghasilkan omzet, dan paling menghasilkan gross profit.</p>
  </a>
  <a class="card" href="<?= APP_BASE_PATH ?>/reports/inventory.php" style="text-decoration:none">
    <strong>Inventaris</strong>
    <p class="text-muted" style="margin-bottom:0">Stok saat ini, stok rendah/habis, dan nilai persediaan berdasarkan harga modal.</p>
  </a>
  <a class="card" href="<?= APP_BASE_PATH ?>/reports/purchases.php" style="text-decoration:none">
    <strong>Pembelian</strong>
    <p class="text-muted" style="margin-bottom:0">Total pembelian per periode/supplier, dan histori harga beli per produk.</p>
  </a>
  <a class="card" href="<?= APP_BASE_PATH ?>/cash/index.php" style="text-decoration:none">
    <strong>Kas / Cash Session</strong>
    <p class="text-muted" style="margin-bottom:0">Riwayat sesi kas: kas awal, diharapkan, aktual, dan selisih.</p>
  </a>
  <a class="card" href="<?= APP_BASE_PATH ?>/sales/index.php" style="text-decoration:none">
    <strong>Riwayat Transaksi</strong>
    <p class="text-muted" style="margin-bottom:0">Cari transaksi per nomor, tanggal, produk, metode bayar, kasir, atau status.</p>
  </a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
