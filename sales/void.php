<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_role(['owner']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ?');
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    flash_set('error', 'Transaksi tidak ditemukan.');
    redirect('/sales/index.php');
}
if ($sale['status'] !== 'completed') {
    flash_set('error', 'Transaksi ini sudah berstatus ' . $sale['status'] . ', tidak bisa divoid lagi.');
    redirect('/sales/view.php?id=' . $id);
}

$pageTitle = 'Void Transaksi ' . $sale['sale_number'];
$backUrl = APP_BASE_PATH . '/sales/view.php?id=' . $id;
$backLabel = 'Kembali ke Detail Transaksi';

$itemsStmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ?');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$paymentStmt = $pdo->prepare('SELECT * FROM payments WHERE sale_id = ?');
$paymentStmt->execute([$id]);
$payment = $paymentStmt->fetch();

$errors = [];

if (is_post()) {
    verify_csrf();
    $reason = post('reason');

    if ($reason === '') {
        $errors[] = 'Alasan void wajib diisi.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $guard = $pdo->prepare('SELECT status FROM sales WHERE id = ? FOR UPDATE');
            $guard->execute([$id]);
            if ($guard->fetchColumn() !== 'completed') {
                throw new RuntimeException('Transaksi ini sudah divoid sebelumnya.');
            }

            foreach ($items as $item) {
                record_stock_movement(
                    $pdo, (int) $item['product_id'], 'sale_void', (int) $item['qty_base'],
                    $item['unit_cost_snapshot'] !== null ? (float) $item['unit_cost_snapshot'] : null,
                    'sale', $id, $actor['id'], $reason
                );
            }

            $update = $pdo->prepare(
                'UPDATE sales SET status = "void", void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ? AND status = "completed"'
            );
            $update->execute([$reason, $actor['id'], $id]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Gagal memvoid transaksi (kemungkinan sudah diproses).');
            }

            $cashWarning = null;
            if ($payment && $payment['method'] === 'cash') {
                $activeSession = get_active_cash_session($pdo);
                if ($activeSession) {
                    record_cash_movement(
                        $pdo, (int) $activeSession['id'], 'cash_sale_void', -(float) $payment['amount'], 'sale', $id, $actor['id'], $reason
                    );
                } else {
                    $cashWarning = 'Void berhasil, tetapi tidak ada sesi kas aktif sehingga penyesuaian kas tidak otomatis tercatat. Sesuaikan kas secara manual saat sesi berikutnya dibuka.';
                }
            }

            log_audit($pdo, 'sale_voided', 'sale', $id, ['status' => 'completed'], ['status' => 'void'], $reason);

            $pdo->commit();

            if ($cashWarning) {
                flash_set('warning', $cashWarning);
            }
            flash_set('success', 'Transaksi ' . $sale['sale_number'] . ' berhasil divoid. Stok telah dikembalikan.');
            redirect('/sales/view.php?id=' . $id);
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
  <p><strong><?= e($sale['sale_number']) ?></strong> &middot; Total: <?= rupiah($sale['total']) ?></p>
  <table>
    <thead><tr><th>Produk</th><th>Unit</th><th class="text-right">Qty</th><th class="text-right">Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['product_code_snapshot']) ?> — <?= e($it['product_name_snapshot']) ?></td>
        <td><?= e($it['unit_name_snapshot']) ?></td>
        <td class="text-right"><?= (int) $it['qty_unit'] ?></td>
        <td class="text-right"><?= rupiah($it['line_total']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="max-width:480px">
  <strong>Void akan langsung:</strong>
  <ul>
    <li>Mengembalikan seluruh stok item ke saldo produk</li>
    <li>Mengeluarkan transaksi ini dari revenue/COGS aktif</li>
    <?php if ($payment && $payment['method'] === 'cash'): ?>
      <li>Mengurangi expected cash sesi aktif sebesar <?= rupiah($payment['amount']) ?> (pembayaran ini tunai)</li>
    <?php endif; ?>
    <li>Mengunci transaksi asli sebagai VOID (tidak dihapus, tetap bisa dilihat di riwayat)</li>
  </ul>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="form-group">
      <label for="reason">Alasan Void (wajib)</label>
      <textarea id="reason" name="reason" rows="3" required></textarea>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn btn-danger" data-confirm="Void transaksi ini? Tindakan ini tidak bisa dibatalkan.">Void Transaksi</button>
      <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/sales/view.php?id=<?= $id ?>">Batal</a>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
