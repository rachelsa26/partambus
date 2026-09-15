<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_role(['owner']);
$pageTitle = 'Catat Stok Awal';

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
    echo '<p class="text-muted">Pilih produk yang akan dicatat stok awalnya.</p>';
    echo '<div id="picker-search-results" data-ajax-url="' . e(APP_BASE_PATH . '/inventory/initial_stock.php') . '">';
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
    redirect('/inventory/initial_stock.php');
}

$unitStmt = $pdo->prepare('SELECT * FROM product_units WHERE product_id = ? AND active = 1 ORDER BY is_base DESC, unit_name');
$unitStmt->execute([$productId]);
$units = $unitStmt->fetchAll();

$errors = [];

if (is_post()) {
    verify_csrf();

    $unitId = (int) post('unit_id');
    $qty = post('qty');
    $costPerUnit = post('cost_per_unit');
    $note = post('note');

    $unit = null;
    foreach ($units as $u) {
        if ((int) $u['id'] === $unitId) {
            $unit = $u;
            break;
        }
    }

    if (!$unit) {
        $errors[] = 'Unit tidak valid.';
    }
    if (!ctype_digit($qty) || (int) $qty <= 0) {
        $errors[] = 'Jumlah harus bilangan bulat positif.';
    }
    if ($costPerUnit !== '' && (!is_numeric($costPerUnit) || (float) $costPerUnit < 0)) {
        $errors[] = 'Biaya modal per unit harus angka >= 0, atau kosongkan jika belum diketahui.';
    }

    if (!$errors) {
        $conversion = (int) $unit['conversion_factor'];
        $qtyBase = (int) $qty * $conversion;
        $costPerBase = $costPerUnit !== '' ? (float) $costPerUnit / $conversion : null;

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT current_stock_base, current_wac FROM products WHERE id = ? FOR UPDATE');
            $lock->execute([$productId]);
            $locked = $lock->fetch();
            $oldStock = (int) $locked['current_stock_base'];
            $oldWac = $locked['current_wac'] !== null ? (float) $locked['current_wac'] : null;

            $result = record_stock_movement(
                $pdo, $productId, 'initial_stock', $qtyBase, $costPerBase, null, null, $actor['id'], $note !== '' ? $note : null
            );

            if ($costPerBase !== null) {
                $newWac = calculate_new_wac($oldWac, $oldStock, $qtyBase, $costPerBase);
                $pdo->prepare('UPDATE products SET current_wac = ? WHERE id = ?')->execute([$newWac, $productId]);
            }

            log_audit($pdo, 'initial_stock_recorded', 'product', $productId, null, [
                'unit' => $unit['unit_name'],
                'qty_unit' => (int) $qty,
                'qty_base' => $qtyBase,
                'cost_per_unit' => $costPerUnit !== '' ? (float) $costPerUnit : null,
                'balance_after' => $result['balance_after'],
            ]);

            $pdo->commit();

            if ($costPerBase === null) {
                flash_set('warning', 'Stok awal dicatat (' . $qtyBase . ' ' . $product['base_unit_name'] . '). Biaya modal belum diisi — gross profit produk ini belum akurat sampai biaya modal diketahui.');
            } else {
                flash_set('success', 'Stok awal dicatat: ' . $qtyBase . ' ' . $product['base_unit_name'] . '. Harga modal produk diperbarui.');
            }
            redirect('/products/edit.php?id=' . $productId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Gagal mencatat stok: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:560px">
  <strong><?= e($product['name']) ?></strong> (<?= e($product['code']) ?>)
  <p class="text-muted">
    Stok saat ini: <?= (int) $product['current_stock_base'] ?> <?= e($product['base_unit_name']) ?> &middot;
    Harga modal saat ini: <?= $product['current_wac'] !== null ? rupiah($product['current_wac']) : 'belum diketahui' ?>
  </p>
</div>

<div class="card" style="max-width:560px">
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
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
    <label for="cost_per_unit">Biaya Modal per Satuan Terpilih (opsional)</label>
    <input type="number" id="cost_per_unit" name="cost_per_unit" min="0" step="1">
    <p class="form-hint">Kosongkan jika belum tahu harga modalnya. Harga modal tidak akan berubah, tapi stok tetap tercatat.</p>
  </div>
  <div class="form-group">
    <label for="note">Catatan (opsional)</label>
    <input type="text" id="note" name="note">
  </div>
  <div class="btn-row">
    <button type="submit" class="btn">Simpan Stok Awal</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/products/edit.php?id=<?= $productId ?>">Batal</a>
  </div>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
