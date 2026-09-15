<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT s.*, u.full_name AS cashier_name, vb.full_name AS voided_by_name
     FROM sales s JOIN users u ON u.id = s.cashier_id LEFT JOIN users vb ON vb.id = s.voided_by
     WHERE s.id = ?'
);
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    flash_set('error', 'Transaksi tidak ditemukan.');
    redirect('/sales/index.php');
}
if (!is_owner() && (int) $sale['cashier_id'] !== (int) $actor['id']) {
    http_response_code(403);
    die('Akses ditolak.');
}

$pageTitle = 'Transaksi ' . $sale['sale_number'];
$backUrl = APP_BASE_PATH . '/sales/index.php';
$backLabel = 'Kembali ke Riwayat Penjualan';
$justCompleted = isset($_GET['just_completed']) && $sale['status'] === 'completed';

$itemsStmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$paymentStmt = $pdo->prepare('SELECT * FROM payments WHERE sale_id = ?');
$paymentStmt->execute([$id]);
$payment = $paymentStmt->fetch();

$methodLabels = PAYMENT_METHOD_LABELS;
$statusLabels = ['completed' => 'Selesai', 'void' => 'Void'];

// ---- AJAX: struk preview fragment, shared by the "Lihat Struk" modal and
// the "Cetak" print flow on this page, and by POS's checkout success view. ----
if (($_GET['ajax'] ?? null) === 'receipt') {
    $settings = get_all_settings($pdo);
    $storeName = $settings['store_name'] ?? 'Toko';
    $storeAddress = $settings['store_address'] ?? '';
    $storePhone = $settings['store_phone'] ?? '';
    $createdAt = new DateTimeImmutable((string) $sale['created_at']);
    $waText = partambus_receipt_wa_text($sale, $items, $payment, $settings, $methodLabels);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <div class="receipt-box">
      <div class="receipt-header">
        <div class="receipt-store-name"><?= e($storeName) ?></div>
        <?php if ($storeAddress !== ''): ?><div class="receipt-line"><?= e($storeAddress) ?></div><?php endif; ?>
        <?php if ($storePhone !== ''): ?><div class="receipt-line">Telp. <?= e($storePhone) ?></div><?php endif; ?>
      </div>
      <div class="receipt-divider"></div>
      <div class="receipt-meta">
        <div>No. Transaksi : <?= e($sale['sale_number']) ?></div>
        <div>Tanggal&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <?= e(indo_date($createdAt) . ', ' . $createdAt->format('H:i')) ?></div>
        <div>Kasir&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <?= e($sale['cashier_name']) ?></div>
      </div>
      <div class="receipt-divider"></div>
      <div class="receipt-items">
        <?php foreach ($items as $it): ?>
          <div class="receipt-item">
            <div><?= e($it['product_name_snapshot']) ?></div>
            <div class="receipt-line-row">
              <span><?= (int) $it['qty_unit'] ?> x <?= e($it['unit_name_snapshot']) ?> @ <?= number_format((float) $it['unit_price'], 0, ',', '.') ?></span>
              <span><?= number_format((float) $it['line_total'], 0, ',', '.') ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="receipt-divider"></div>
      <div class="receipt-line-row"><span>Subtotal</span><span><?= number_format((float) $sale['subtotal'], 0, ',', '.') ?></span></div>
      <div class="receipt-line-row"><span>Diskon</span><span><?= number_format((float) $sale['discount_total'], 0, ',', '.') ?></span></div>
      <div class="receipt-divider"></div>
      <div class="receipt-line-row receipt-total-row"><span>TOTAL</span><span><?= number_format((float) $sale['total'], 0, ',', '.') ?></span></div>
      <?php if ($payment): ?>
        <div class="receipt-divider"></div>
        <div class="receipt-line-row"><span>Metode Bayar</span><span><?= e($methodLabels[$payment['method']] ?? $payment['method']) ?></span></div>
        <?php if ($payment['method'] === 'cash'): ?>
          <div class="receipt-line-row"><span>Dibayar</span><span><?= number_format((float) $payment['cash_received'], 0, ',', '.') ?></span></div>
          <div class="receipt-line-row"><span>Kembalian</span><span><?= number_format((float) $payment['change_amount'], 0, ',', '.') ?></span></div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="receipt-divider"></div>
      <div class="receipt-footer">Terima kasih telah berbelanja di <?= e($storeName) ?>!</div>
    </div>
    <textarea id="pos-receipt-wa-text" class="hidden" readonly><?= e($waText) ?></textarea>
    <?php
    exit;
}

// Reused as-is from before this redesign — same WAC-derived cogs_total
// snapshots already stored per sale_item at sale time, nothing recomputed.
$totalCogs = null;
$unknownCost = false;
if (is_owner()) {
    $totalCogs = 0.0;
    foreach ($items as $it) {
        if ($it['cogs_total'] === null) {
            $unknownCost = true;
        } else {
            $totalCogs += (float) $it['cogs_total'];
        }
    }
}
$grossProfit = $totalCogs !== null ? (float) $sale['total'] - $totalCogs : null;

ob_start();
?>
<button type="button" class="btn btn-outline-primary" id="sale-view-receipt-btn"><?= partambus_icon('invoice', 15) ?> Lihat Struk</button>
<button type="button" class="btn btn-outline-primary" id="sale-print-btn"><?= partambus_icon('printer', 15) ?> Cetak</button>
<?php
$topbarExtra = ob_get_clean();

require __DIR__ . '/../includes/header.php';
?>

<?php if ($justCompleted): ?>
<div class="card pos-success-card" id="pos-success-banner">
  <p class="pos-success-title">&#10003; Transaksi Berhasil!</p>
  <p style="margin:0">No. Transaksi: <strong><?= e($sale['sale_number']) ?></strong></p>
  <?php if ($payment && $payment['method'] === 'cash'): ?>
    <p class="pos-success-change-label" style="margin:12px 0 0">Kembalian</p>
    <p class="pos-success-change-amount" style="margin:2px 0 0"><?= rupiah($payment['change_amount']) ?></p>
  <?php endif; ?>
  <div class="btn-row">
    <a class="btn btn-xlarge" href="<?= APP_BASE_PATH ?>/pos/index.php">+ Mulai Transaksi Baru</a>
  </div>
</div>
<?php endif; ?>

<div class="card sale-info-card">
  <div class="sale-info-left">
    <div class="sale-info-icon"><?= partambus_icon('invoice', 22) ?></div>
    <div>
      <div class="sale-info-title-row">
        <strong style="font-size:20px"><?= e($sale['sale_number']) ?></strong>
        <span class="badge <?= $sale['status'] === 'completed' ? 'badge-active' : 'badge-danger' ?>">
          <?php if ($sale['status'] === 'completed'): ?><?= partambus_icon('check', 11) ?><?php endif; ?>
          <?= e($statusLabels[$sale['status']] ?? $sale['status']) ?>
        </span>
      </div>
      <div class="sale-info-meta">
        <span><?= partambus_icon('users', 14) ?> Kasir: <?= e($sale['cashier_name']) ?></span>
        <span><?= partambus_icon('calendar', 14) ?> <?= e($sale['created_at']) ?></span>
        <?php if ($payment): ?><span><?= partambus_icon('wallet', 14) ?> Metode: <?= e($methodLabels[$payment['method']] ?? $payment['method']) ?></span><?php endif; ?>
      </div>
      <?php if ($sale['status'] === 'void'): ?>
        <p class="text-danger" style="margin-top:10px">Dibatalkan (void) oleh <?= e($sale['voided_by_name'] ?? '-') ?> pada <?= e((string) $sale['voided_at']) ?><br>Alasan: <?= e((string) $sale['void_reason']) ?></p>
      <?php endif; ?>
    </div>
  </div>
  <div class="sale-info-right">
    <div class="sale-mini-card sale-mini-card-blue">
      <span class="sale-mini-card-icon"><?= partambus_icon('wallet', 16) ?></span>
      <div>
        <div class="sale-mini-card-label">Total Transaksi</div>
        <div class="sale-mini-card-value"><?= rupiah($sale['total']) ?></div>
      </div>
    </div>
    <?php if (is_owner()): ?>
      <div class="sale-mini-card sale-mini-card-purple">
        <span class="sale-mini-card-icon"><?= partambus_icon('box', 16) ?></span>
        <div>
          <div class="sale-mini-card-label">Total HPP</div>
          <div class="sale-mini-card-value"><?= $totalCogs !== null ? rupiah($totalCogs) : '?' ?></div>
        </div>
      </div>
      <div class="sale-mini-card sale-mini-card-green">
        <span class="sale-mini-card-icon"><?= partambus_icon('trending-up', 16) ?></span>
        <div>
          <div class="sale-mini-card-label">Gross Profit</div>
          <div class="sale-mini-card-value sale-mini-card-value-green"><?= $grossProfit !== null ? rupiah($grossProfit) : '?' ?></div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="sale-detail-grid">
  <div class="card">
    <strong style="font-size:16px"><?= partambus_icon('cart', 18) ?> Daftar Produk</strong>
    <table style="margin-top:12px">
      <thead>
        <tr>
          <th>Produk</th><th>Unit</th><th class="text-right">Qty</th><th class="text-right">Harga</th><th class="text-right">Diskon</th><th class="text-right">Subtotal</th>
          <?php if (is_owner()): ?><th class="text-right">HPP</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_code_snapshot']) ?> — <?= e($it['product_name_snapshot']) ?></td>
          <td><?= e($it['unit_name_snapshot']) ?></td>
          <td class="text-right"><?= (int) $it['qty_unit'] ?></td>
          <td class="text-right"><?= rupiah($it['unit_price']) ?></td>
          <td class="text-right"><?= rupiah($it['discount_amount']) ?></td>
          <td class="text-right"><?= rupiah($it['line_total']) ?></td>
          <?php if (is_owner()): ?>
            <td class="text-right"><?= $it['cogs_total'] !== null ? rupiah($it['cogs_total']) : '<span class="text-muted">?</span>' ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="sale-totals">
      <div class="sale-total-row"><span>Subtotal</span><span><?= rupiah($sale['subtotal']) ?></span></div>
      <div class="sale-total-row"><span>Diskon</span><span>-<?= rupiah($sale['discount_total']) ?></span></div>
      <div class="sale-total-row sale-total-row-highlight"><span>Total</span><span><?= rupiah($sale['total']) ?></span></div>
    </div>
  </div>

  <div class="sale-side-col">
    <?php if ($payment): ?>
    <div class="card">
      <div class="sale-card-title-row">
        <strong style="font-size:16px">Pembayaran</strong>
        <span class="sale-card-title-icon sale-card-title-icon-green"><?= partambus_icon('check', 13) ?></span>
      </div>
      <div class="sale-payment-rows">
        <div class="sale-payment-row"><span><?= partambus_icon('wallet', 15) ?> Metode</span><strong><?= e($methodLabels[$payment['method']] ?? $payment['method']) ?></strong></div>
        <?php if ($payment['method'] === 'cash'): ?>
          <div class="sale-payment-row"><span><?= partambus_icon('download', 15) ?> Diterima</span><strong><?= rupiah($payment['cash_received']) ?></strong></div>
          <div class="sale-payment-row"><span><?= partambus_icon('refresh', 15) ?> Kembalian</span><strong><?= rupiah($payment['change_amount']) ?></strong></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (is_owner()): ?>
    <div class="card">
      <div class="sale-card-title-row">
        <strong style="font-size:16px">Ringkasan Laba</strong>
        <span class="sale-card-title-icon sale-card-title-icon-green"><?= partambus_icon('trending-up', 13) ?></span>
      </div>
      <div class="sale-payment-rows">
        <div class="sale-payment-row"><span>Total HPP<?= $unknownCost ? ' *' : '' ?></span><strong><?= $totalCogs !== null ? rupiah($totalCogs) : '?' ?></strong></div>
        <div class="sale-payment-row"><span>Gross Profit</span><strong class="text-success"><?= $grossProfit !== null ? rupiah($grossProfit) : '?' ?></strong></div>
      </div>
      <?php if ($unknownCost): ?><p class="text-muted text-small" style="margin-top:8px">* Sebagian harga modal belum diketahui.</p><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="btn-row">
  <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/sales/index.php">&larr; Kembali ke Riwayat</a>
  <a class="btn" href="<?= APP_BASE_PATH ?>/pos/index.php"><?= partambus_icon('plus', 15) ?> Transaksi Baru</a>
  <?php if (is_owner() && $sale['status'] === 'completed'): ?>
    <button type="button" class="btn btn-danger" id="sale-void-open-btn"><?= partambus_icon('trash', 15) ?> Void Transaksi</button>
  <?php endif; ?>
</div>

<?php if (is_owner() && $sale['status'] === 'completed'): ?>
<div class="modal-overlay hidden" id="sale-void-modal">
  <div class="modal-box void-modal-box">
    <button type="button" class="modal-close-btn" data-modal-cancel aria-label="Tutup"><?= partambus_icon('x-circle', 20) ?></button>

    <div class="void-modal-header">
      <span class="void-modal-header-icon"><?= partambus_icon('warning', 22) ?></span>
      <div>
        <strong style="font-size:18px">Void Transaksi</strong>
        <p class="text-muted" style="margin:2px 0 0;font-size:13px">Tindakan ini akan membatalkan transaksi dan tidak dapat diubah.</p>
      </div>
    </div>

    <div class="void-summary-box">
      <div class="void-summary-item">
        <span class="void-summary-icon"><?= partambus_icon('invoice', 15) ?></span>
        <div><div class="void-summary-label">No. Transaksi</div><div class="void-summary-value"><?= e($sale['sale_number']) ?></div></div>
      </div>
      <div class="void-summary-item">
        <span class="void-summary-icon"><?= partambus_icon('wallet', 15) ?></span>
        <div><div class="void-summary-label">Total</div><div class="void-summary-value text-danger"><?= rupiah($sale['total']) ?></div></div>
      </div>
      <?php if ($payment): ?>
      <div class="void-summary-item">
        <span class="void-summary-icon"><?= partambus_icon('money', 15) ?></span>
        <div><div class="void-summary-label">Metode</div><div class="void-summary-value"><?= e($methodLabels[$payment['method']] ?? $payment['method']) ?></div></div>
      </div>
      <?php endif; ?>
      <div class="void-summary-item">
        <span class="void-summary-icon"><?= partambus_icon('users', 15) ?></span>
        <div><div class="void-summary-label">Waktu / Kasir</div><div class="void-summary-value"><?= e(fmt_date($sale['created_at'])) ?><br><?= e($sale['cashier_name']) ?></div></div>
      </div>
    </div>

    <strong style="font-size:14px;display:block;margin-top:16px">Ringkasan Produk</strong>
    <table style="margin-top:8px">
      <thead><tr><th>Produk</th><th>Unit</th><th class="text-right">Qty</th><th class="text-right">Subtotal</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= partambus_icon('box', 13) ?> <?= e($it['product_code_snapshot']) ?> — <?= e($it['product_name_snapshot']) ?></td>
          <td><?= e($it['unit_name_snapshot']) ?></td>
          <td class="text-right"><?= (int) $it['qty_unit'] ?></td>
          <td class="text-right"><?= rupiah($it['line_total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="void-warning-box">
      <div class="void-warning-title">Apa yang terjadi jika transaksi ini di-void?</div>
      <ul class="void-warning-list">
        <li><?= partambus_icon('box', 14) ?> Stok item akan dikembalikan ke inventori.</li>
        <li><?= partambus_icon('trending-up', 14) ?> Revenue/HPP dari transaksi ini akan dibatalkan.</li>
        <?php if ($payment && $payment['method'] === 'cash'): ?>
          <li><?= partambus_icon('money', 14) ?> Cash session akan disesuaikan sesuai pembayaran (<?= rupiah($payment['amount']) ?>).</li>
        <?php endif; ?>
        <li><?= partambus_icon('invoice', 14) ?> Transaksi ini akan ditandai sebagai VOID dan tetap tersimpan di riwayat.</li>
      </ul>
    </div>

    <form method="post" action="<?= APP_BASE_PATH ?>/sales/void.php" id="void-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="form-group" style="margin-top:14px">
        <label for="void-reason-input">Alasan Void (wajib)</label>
        <textarea id="void-reason-input" name="reason" rows="3" placeholder="Tuliskan alasan pembatalan transaksi ini..." required></textarea>
        <p class="form-hint">Berikan alasan yang jelas untuk membantu proses audit dan pelaporan.</p>
      </div>
      <div class="btn-row" style="justify-content:flex-end;margin-top:18px">
        <button type="button" class="btn btn-secondary" data-modal-cancel>Batal</button>
        <button type="submit" class="btn btn-danger" id="void-submit-btn" disabled data-confirm="Void transaksi ini? Tindakan ini tidak bisa dibatalkan."><?= partambus_icon('warning', 15) ?> Void Transaksi</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="modal-overlay hidden" id="sale-receipt-modal">
  <div class="modal-box" style="max-width:460px">
    <strong style="font-size:16px">Preview Struk</strong>
    <p class="text-muted" style="margin-top:2px;font-size:12px">Struk transaksi <?= e($sale['sale_number']) ?></p>
    <div id="sale-receipt-modal-body" style="margin-top:12px">
      <p class="text-muted">Memuat...</p>
    </div>
    <div class="btn-row" style="justify-content:flex-end;margin-top:14px">
      <button type="button" class="btn btn-secondary" data-modal-cancel>Tutup</button>
    </div>
  </div>
</div>

<div id="sale-print-area"></div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
