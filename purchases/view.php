<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT pu.*, s.name AS supplier_name, cb.full_name AS created_by_name, xb.full_name AS confirmed_by_name
     FROM purchases pu
     JOIN suppliers s ON s.id = pu.supplier_id
     LEFT JOIN users cb ON cb.id = pu.created_by
     LEFT JOIN users xb ON xb.id = pu.confirmed_by
     WHERE pu.id = ?'
);
$stmt->execute([$id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    flash_set('error', 'Pembelian tidak ditemukan.');
    redirect('/purchases/index.php');
}

$pageTitle = 'Pembelian ' . $purchase['purchase_number'];
$backUrl = APP_BASE_PATH . '/purchases/index.php';
$backLabel = 'Kembali ke Daftar Pembelian';

$itemsStmt = $pdo->prepare(
    'SELECT pi.*, p.code AS product_code, p.name AS product_name, p.base_unit_name
     FROM purchase_items pi JOIN products p ON p.id = pi.product_id
     WHERE pi.purchase_id = ? ORDER BY pi.id'
);
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$statusLabels = ['draft' => 'Draft', 'confirmed' => 'Terkonfirmasi', 'cancelled_draft' => 'Draft Dibatalkan'];
$statusBadge = ['draft' => 'inactive', 'confirmed' => 'active', 'cancelled_draft' => 'inactive'];
$paymentLabels = ['cash_drawer' => 'Kas Laci Toko', 'external' => 'Dana Luar / Pribadi Owner'];

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <p>
    <strong>Supplier:</strong> <?= e($purchase['supplier_name']) ?> &middot;
    <span class="badge badge-<?= e($statusBadge[$purchase['status']]) ?>"><?= e($statusLabels[$purchase['status']]) ?></span>
  </p>
  <p class="text-muted">
    Dibuat oleh <?= e($purchase['created_by_name'] ?? '-') ?> pada <?= e(fmt_date($purchase['created_at'])) ?>
    <?php if ($purchase['status'] === 'confirmed'): ?>
      &middot; Dikonfirmasi oleh <?= e($purchase['confirmed_by_name'] ?? '-') ?> pada <?= e($purchase['confirmed_at']) ?>
      &middot; Pembayaran: <?= e($paymentLabels[$purchase['payment_source']] ?? '-') ?>
    <?php endif; ?>
  </p>
  <?php if ($purchase['notes']): ?><p><strong>Catatan:</strong> <?= e($purchase['notes']) ?></p><?php endif; ?>
</div>

<div class="card">
  <table>
    <thead><tr><th>Produk</th><th>Unit</th><th class="text-right">Qty</th><th class="text-right">Qty Dasar</th><th class="text-right">Biaya/Unit</th><th class="text-right">Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['product_code']) ?> — <?= e($it['product_name']) ?></td>
        <td><?= e($it['unit_name_snapshot']) ?></td>
        <td class="text-right"><?= (int) $it['qty_unit'] ?></td>
        <td class="text-right"><?= (int) $it['qty_base'] ?> <?= e($it['base_unit_name']) ?></td>
        <td class="text-right"><?= rupiah($it['unit_cost']) ?></td>
        <td class="text-right"><?= rupiah($it['subtotal']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="5" class="text-right"><strong>Total</strong></td><td class="text-right"><strong><?= rupiah($purchase['total_amount']) ?></strong></td></tr>
    </tfoot>
  </table>
</div>

<div class="btn-row">
  <?php if ($purchase['status'] === 'draft'): ?>
    <a class="btn" href="<?= APP_BASE_PATH ?>/purchases/edit.php?id=<?= $id ?>">Lanjutkan Edit Draft</a>
  <?php endif; ?>
  <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/purchases/index.php">Kembali ke Daftar</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
