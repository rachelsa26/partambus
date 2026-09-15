<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_role(['owner']);
$pageTitle = 'Penyesuaian Stok';

$productId = (int) ($_GET['product_id'] ?? 0);
$backUrl = $productId > 0 ? APP_BASE_PATH . '/products/edit.php?id=' . $productId : APP_BASE_PATH . '/inventory/index.php';
$backLabel = $productId > 0 ? 'Kembali ke Detail Produk' : 'Kembali ke Inventaris';

if ($productId <= 0) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: text/html; charset=UTF-8');
        require __DIR__ . '/../includes/product_picker.php';
        exit;
    }
    require __DIR__ . '/../includes/header.php';
    echo '<p class="text-muted">Pilih produk yang stoknya akan disesuaikan.</p>';
    echo '<div id="picker-search-results" data-ajax-url="' . e(APP_BASE_PATH . '/inventory/adjustment.php') . '">';
    require __DIR__ . '/../includes/product_picker.php';
    echo '</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product) {
    flash_set('error', 'Produk tidak ditemukan.');
    redirect('/inventory/adjustment.php');
}

$unitStmt = $pdo->prepare('SELECT * FROM product_units WHERE product_id = ? AND active = 1 ORDER BY is_base DESC, unit_name');
$unitStmt->execute([$productId]);
$units = $unitStmt->fetchAll();

$errors = [];

if (is_post()) {
    verify_csrf();

    $direction = post('direction');
    $unitId = (int) post('unit_id');
    $qty = post('qty');
    $reason = post('reason');

    $unit = null;
    foreach ($units as $u) {
        if ((int) $u['id'] === $unitId) {
            $unit = $u;
            break;
        }
    }

    if (!in_array($direction, ['in', 'out'], true)) {
        $errors[] = 'Arah penyesuaian tidak valid.';
    }
    if (!$unit) {
        $errors[] = 'Unit tidak valid.';
    }
    if (!ctype_digit($qty) || (int) $qty <= 0) {
        $errors[] = 'Jumlah harus bilangan bulat positif.';
    }
    if ($reason === '') {
        $errors[] = 'Alasan wajib diisi untuk setiap penyesuaian stok manual.';
    }

    if (!$errors) {
        $conversion = (int) $unit['conversion_factor'];
        $qtyBase = (int) $qty * $conversion;
        $signedQtyBase = $direction === 'in' ? $qtyBase : -$qtyBase;
        $movementType = $direction === 'in' ? 'adjustment_in' : 'adjustment_out';

        $pdo->beginTransaction();
        try {
            $result = record_stock_movement(
                $pdo, $productId, $movementType, $signedQtyBase, null, null, null, $actor['id'], $reason
            );

            log_audit($pdo, 'stock_adjustment', 'product', $productId, null, [
                'direction' => $direction,
                'unit' => $unit['unit_name'],
                'qty_unit' => (int) $qty,
                'qty_base' => $signedQtyBase,
                'balance_after' => $result['balance_after'],
            ], $reason);

            $pdo->commit();
            flash_set('success', 'Penyesuaian stok tersimpan. Saldo baru: ' . $result['balance_after'] . ' ' . $product['base_unit_name'] . '.');
            redirect('/products/edit.php?id=' . $productId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:560px">
  <strong><?= e($product['name']) ?></strong> (<?= e($product['code']) ?>)
  <p class="text-muted">Stok saat ini: <?= (int) $product['current_stock_base'] ?> <?= e($product['base_unit_name']) ?></p>
</div>

<div class="card" style="max-width:560px">
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-group">
    <label>Arah</label>
    <div class="form-row">
      <label class="checkbox-inline"><input type="radio" name="direction" value="in" checked> Stok masuk (ditemukan/koreksi tambah)</label>
      <label class="checkbox-inline"><input type="radio" name="direction" value="out"> Stok keluar (hilang/rusak/koreksi kurang)</label>
    </div>
  </div>
  <div class="form-row">
    <div class="form-group">
      <label for="unit_id">Satuan</label>
      <select id="unit_id" name="unit_id" required>
        <?php foreach ($units as $u): ?>
          <option value="<?= (int) $u['id'] ?>">
            <?= e($u['unit_name']) ?><?= $u['is_base'] ? ' (dasar)' : ' = ' . (int) $u['conversion_factor'] . ' ' . e($product['base_unit_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label for="qty">Jumlah</label>
      <input type="number" id="qty" name="qty" min="1" step="1" required>
    </div>
  </div>
  <div class="form-group">
    <label for="reason">Alasan (wajib)</label>
    <textarea id="reason" name="reason" rows="3" required></textarea>
  </div>
  <div class="btn-row">
    <button type="submit" class="btn">Simpan Penyesuaian</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/products/edit.php?id=<?= $productId ?>">Batal</a>
  </div>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
