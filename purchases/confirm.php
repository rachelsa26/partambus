<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_role(['owner']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT pu.*, s.name AS supplier_name FROM purchases pu JOIN suppliers s ON s.id = pu.supplier_id WHERE pu.id = ?'
);
$stmt->execute([$id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    flash_set('error', 'Pembelian tidak ditemukan.');
    redirect('/purchases/index.php');
}
if ($purchase['status'] !== 'draft') {
    flash_set('error', 'Pembelian ini sudah tidak berstatus draft.');
    redirect('/purchases/view.php?id=' . $id);
}

$itemsStmt = $pdo->prepare(
    'SELECT pi.*, p.code AS product_code, p.name AS product_name, p.base_unit_name
     FROM purchase_items pi JOIN products p ON p.id = pi.product_id
     WHERE pi.purchase_id = ? ORDER BY pi.id'
);
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

if (!$items) {
    flash_set('error', 'Tambahkan minimal 1 item sebelum konfirmasi.');
    redirect('/purchases/edit.php?id=' . $id);
}

$pageTitle = 'Konfirmasi Pembelian ' . $purchase['purchase_number'];
$backUrl = APP_BASE_PATH . '/purchases/index.php';
$backLabel = 'Kembali ke Daftar Pembelian';
$errors = [];

$activeSession = get_active_cash_session($pdo);

if (is_post()) {
    verify_csrf();
    $paymentSource = post('payment_source');

    if (!in_array($paymentSource, ['cash_drawer', 'external'], true)) {
        $errors[] = 'Pilih sumber pembayaran.';
    }
    if ($paymentSource === 'cash_drawer' && !$activeSession) {
        $errors[] = 'Belum ada sesi kas aktif. Buka sesi kas dulu, atau pilih Dana Luar.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            // Re-check status inside the transaction to guard against a race
            // (e.g. confirmed from another tab a moment ago).
            $guard = $pdo->prepare('SELECT status FROM purchases WHERE id = ? FOR UPDATE');
            $guard->execute([$id]);
            $currentStatus = $guard->fetchColumn();
            if ($currentStatus !== 'draft') {
                throw new RuntimeException('Pembelian ini sudah dikonfirmasi/dibatalkan sebelumnya.');
            }

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $qtyBase = (int) $item['qty_base'];
                $costPerBase = (float) $item['cost_per_base'];

                $lock = $pdo->prepare('SELECT current_stock_base, current_wac FROM products WHERE id = ? FOR UPDATE');
                $lock->execute([$productId]);
                $locked = $lock->fetch();
                $oldStock = (int) $locked['current_stock_base'];
                $oldWac = $locked['current_wac'] !== null ? (float) $locked['current_wac'] : null;

                record_stock_movement(
                    $pdo, $productId, 'purchase', $qtyBase, $costPerBase, 'purchase', $id, $actor['id'], null
                );

                $newWac = calculate_new_wac($oldWac, $oldStock, $qtyBase, $costPerBase);
                $pdo->prepare('UPDATE products SET current_wac = ? WHERE id = ?')->execute([$newWac, $productId]);
            }

            $update = $pdo->prepare(
                'UPDATE purchases SET status = "confirmed", payment_source = ?, confirmed_by = ?, confirmed_at = NOW()
                 WHERE id = ? AND status = "draft"'
            );
            $update->execute([$paymentSource, $actor['id'], $id]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Gagal mengonfirmasi pembelian (kemungkinan sudah diproses).');
            }

            log_audit($pdo, 'purchase_confirmed', 'purchase', $id, ['status' => 'draft'], [
                'status' => 'confirmed',
                'payment_source' => $paymentSource,
                'total_amount' => (float) $purchase['total_amount'],
                'item_count' => count($items),
            ]);

            if ($paymentSource === 'cash_drawer') {
                record_cash_movement(
                    $pdo, (int) $activeSession['id'], 'purchase_payment', -(float) $purchase['total_amount'], 'purchase', $id, $actor['id'], null
                );
            }

            $pdo->commit();
            flash_set('success', 'Pembelian ' . $purchase['purchase_number'] . ' berhasil dikonfirmasi. Stok dan harga modal produk sudah diperbarui.');
            redirect('/purchases/view.php?id=' . $id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card">
  <p><strong>Supplier:</strong> <?= e($purchase['supplier_name']) ?></p>
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

<div class="card" style="max-width:480px">
  <strong>Konfirmasi akan langsung:</strong>
  <ul>
    <li>Menambah stok setiap produk sesuai qty dasar di atas</li>
    <li>Memperbarui harga modal tiap produk (dihitung otomatis dari rata-rata harga pembelian)</li>
    <li>Mengunci pembelian ini (tidak bisa diedit lagi)</li>
  </ul>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="form-group">
      <label>Sumber Pembayaran</label>
      <?php if (!$activeSession): ?>
        <p class="flash flash-warning" style="margin-bottom:8px">Kas Laci tidak bisa dipilih: belum ada sesi kas aktif. <a href="<?= APP_BASE_PATH ?>/cash/open.php">Buka sesi kas</a> dulu, atau pilih Dana Luar.</p>
      <?php endif; ?>
      <div class="form-row">
        <label class="checkbox-inline"><input type="radio" name="payment_source" value="cash_drawer" required <?= !$activeSession ? 'disabled' : '' ?>> Kas Laci Toko</label>
        <label class="checkbox-inline"><input type="radio" name="payment_source" value="external" <?= !$activeSession ? 'checked' : '' ?>> Dana Luar / Pribadi Owner</label>
      </div>
      <p class="form-hint">Kas Laci akan langsung mengurangi expected cash pada sesi kas aktif.</p>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn" data-confirm="Konfirmasi pembelian ini? Aksi ini tidak bisa dibatalkan.">Konfirmasi &amp; Proses</button>
      <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/purchases/edit.php?id=<?= $id ?>">Kembali Edit Draft</a>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
