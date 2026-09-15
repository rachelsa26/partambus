<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_role(['owner']);

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ?');
$stmt->execute([$id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    flash_set('error', 'Pembelian tidak ditemukan.');
    redirect('/purchases/index.php');
}
if ($purchase['status'] !== 'draft') {
    redirect('/purchases/view.php?id=' . $id);
}

$pageTitle = 'Draft Pembelian ' . $purchase['purchase_number'];
$backUrl = APP_BASE_PATH . '/purchases/index.php';
$backLabel = 'Kembali ke Daftar Pembelian';

function recompute_purchase_total(PDO $pdo, int $purchaseId): void
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(subtotal), 0) FROM purchase_items WHERE purchase_id = ?');
    $stmt->execute([$purchaseId]);
    $total = (float) $stmt->fetchColumn();
    $pdo->prepare('UPDATE purchases SET total_amount = ? WHERE id = ?')->execute([$total, $purchaseId]);
}

$errors = [];

if (is_post()) {
    verify_csrf();
    $formAction = post('form_action');

    if ($formAction === 'update_header') {
        $supplierId = (int) post('supplier_id');
        $notes = post('notes');
        $check = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? AND active = 1');
        $check->execute([$supplierId]);
        if (!$check->fetch()) {
            $errors[] = 'Pilih supplier yang valid.';
        } else {
            $pdo->prepare('UPDATE purchases SET supplier_id = ?, notes = ? WHERE id = ? AND status = "draft"')
                ->execute([$supplierId, $notes !== '' ? $notes : null, $id]);
            flash_set('success', 'Data pembelian diperbarui.');
            redirect('/purchases/edit.php?id=' . $id);
        }
    } elseif ($formAction === 'add_item') {
        $productId = (int) post('product_id');
        $unitId = (int) post('unit_id');
        $qtyUnit = post('qty_unit');
        $unitCost = post('unit_cost');

        $unitStmt = $pdo->prepare(
            'SELECT pu.*, p.code AS product_code, p.name AS product_name
             FROM product_units pu JOIN products p ON p.id = pu.product_id
             WHERE pu.id = ? AND pu.product_id = ? AND pu.active = 1 AND pu.can_purchase = 1'
        );
        $unitStmt->execute([$unitId, $productId]);
        $unit = $unitStmt->fetch();

        if (!$unit) {
            $errors[] = 'Unit pembelian tidak valid untuk produk ini.';
        }
        if (!ctype_digit($qtyUnit) || (int) $qtyUnit <= 0) {
            $errors[] = 'Jumlah harus bilangan bulat positif.';
        }
        if (!is_numeric($unitCost) || (float) $unitCost <= 0) {
            $errors[] = 'Biaya per satuan harus lebih dari 0.';
        }

        if (!$errors) {
            $conversion = (int) $unit['conversion_factor'];
            $qtyBase = (int) $qtyUnit * $conversion;
            $costPerBase = (float) $unitCost / $conversion;
            $subtotal = (int) $qtyUnit * (float) $unitCost;

            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO purchase_items
                        (purchase_id, product_id, unit_id, unit_name_snapshot, conversion_factor_snapshot, qty_unit, qty_base, unit_cost, cost_per_base, subtotal)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $id, $productId, $unitId, $unit['unit_name'], $conversion, (int) $qtyUnit, $qtyBase, (float) $unitCost, $costPerBase, $subtotal,
                ]);
                recompute_purchase_total($pdo, $id);
                $pdo->commit();
                flash_set('success', 'Item "' . $unit['product_name'] . '" ditambahkan.');
                redirect('/purchases/edit.php?id=' . $id);
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Gagal menambah item: ' . $e->getMessage();
            }
        }
    } elseif ($formAction === 'remove_item') {
        $itemId = (int) post('item_id');
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM purchase_items WHERE id = ? AND purchase_id = ?');
            $del->execute([$itemId, $id]);
            recompute_purchase_total($pdo, $id);
            $pdo->commit();
            flash_set('success', 'Item dihapus dari draft.');
            redirect('/purchases/edit.php?id=' . $id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Gagal menghapus item: ' . $e->getMessage();
        }
    } elseif ($formAction === 'cancel_draft') {
        $pdo->prepare('UPDATE purchases SET status = "cancelled_draft" WHERE id = ? AND status = "draft"')->execute([$id]);
        log_audit($pdo, 'purchase_draft_cancelled', 'purchase', $id, ['status' => 'draft'], ['status' => 'cancelled_draft']);
        flash_set('success', 'Draft pembelian dibatalkan.');
        redirect('/purchases/index.php');
    }

    // reload purchase after any mutation attempt
    $stmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ?');
    $stmt->execute([$id]);
    $purchase = $stmt->fetch();
}

$itemsStmt = $pdo->prepare(
    'SELECT pi.*, p.code AS product_code, p.name AS product_name
     FROM purchase_items pi JOIN products p ON p.id = pi.product_id
     WHERE pi.purchase_id = ? ORDER BY pi.id'
);
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$supplierStmt = $pdo->query('SELECT id, name FROM suppliers WHERE active = 1 ORDER BY name');
$suppliers = $supplierStmt->fetchAll();

$addProductId = (int) ($_GET['add_product_id'] ?? 0);
$addProduct = null;
$addProductUnits = [];
if ($addProductId > 0) {
    $pStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND active = 1');
    $pStmt->execute([$addProductId]);
    $addProduct = $pStmt->fetch();
    if ($addProduct) {
        $uStmt = $pdo->prepare('SELECT * FROM product_units WHERE product_id = ? AND active = 1 AND can_purchase = 1 ORDER BY is_base DESC, unit_name');
        $uStmt->execute([$addProductId]);
        $addProductUnits = $uStmt->fetchAll();
    }
}

require __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card">
  <form method="post" class="form-row" style="align-items:flex-end">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="form_action" value="update_header">
    <div class="form-group">
      <label for="supplier_id">Supplier</label>
      <select id="supplier_id" name="supplier_id">
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (int) $purchase['supplier_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:2">
      <label for="notes">Catatan</label>
      <input type="text" id="notes" name="notes" value="<?= e((string) $purchase['notes']) ?>">
    </div>
    <div class="form-group" style="flex:0 0 auto">
      <button type="submit" class="btn btn-secondary">Simpan</button>
    </div>
  </form>
</div>

<div class="card">
  <strong>Item Pembelian</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Produk</th><th>Unit</th><th class="text-right">Qty</th><th class="text-right">Biaya/Unit</th><th class="text-right">Subtotal</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['product_code']) ?> — <?= e($it['product_name']) ?></td>
        <td><?= e($it['unit_name_snapshot']) ?> <span class="text-muted">(&times;<?= (int) $it['conversion_factor_snapshot'] ?>)</span></td>
        <td class="text-right"><?= (int) $it['qty_unit'] ?></td>
        <td class="text-right"><?= rupiah($it['unit_cost']) ?></td>
        <td class="text-right"><?= rupiah($it['subtotal']) ?></td>
        <td>
          <form method="post" data-confirm="Hapus item ini dari draft?">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="remove_item">
            <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
            <button type="submit" class="btn btn-small btn-secondary">Hapus</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="6" class="empty-state">Belum ada item. Cari produk di bawah untuk ditambahkan.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($items): ?>
    <tfoot>
      <tr><td colspan="4" class="text-right"><strong>Total</strong></td><td class="text-right"><strong><?= rupiah($purchase['total_amount']) ?></strong></td><td></td></tr>
    </tfoot>
    <?php endif; ?>
  </table>
</div>

<div class="card">
  <strong>Tambah Item</strong>
  <?php if (!$addProduct): ?>
    <p class="form-hint">Cari produk, lalu pilih untuk mengisi jumlah &amp; biaya pembelian.</p>
    <?php require __DIR__ . '/../includes/product_picker.php'; ?>
  <?php else: ?>
    <p><?= e($addProduct['code']) ?> — <strong><?= e($addProduct['name']) ?></strong>
      (<a href="?id=<?= $id ?>">ganti produk</a>)</p>
    <?php if (!$addProductUnits): ?>
      <p class="text-muted">Produk ini belum punya unit yang ditandai "bisa dibeli". Edit produk dulu di menu Produk.</p>
    <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="add_item">
      <input type="hidden" name="product_id" value="<?= $addProductId ?>">
      <div class="form-row">
        <div class="form-group">
          <label for="unit_id">Satuan Beli</label>
          <select id="unit_id" name="unit_id" required>
            <?php foreach ($addProductUnits as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= e($u['unit_name']) ?><?= $u['is_base'] ? '' : ' = ' . (int) $u['conversion_factor'] . ' ' . e($addProduct['base_unit_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="qty_unit">Jumlah</label>
          <input type="number" id="qty_unit" name="qty_unit" min="1" step="1" required>
        </div>
        <div class="form-group">
          <label for="unit_cost">Biaya per Satuan</label>
          <input type="number" id="unit_cost" name="unit_cost" min="1" step="1" required>
        </div>
        <div class="form-group" style="flex:0 0 auto">
          <button type="submit" class="btn">Tambah ke Draft</button>
        </div>
      </div>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="btn-row">
  <?php if ($items): ?>
    <a class="btn" href="<?= APP_BASE_PATH ?>/purchases/confirm.php?id=<?= $id ?>">Lanjut Konfirmasi Pembelian</a>
  <?php else: ?>
    <button class="btn" disabled title="Tambah minimal 1 item dulu">Lanjut Konfirmasi Pembelian</button>
  <?php endif; ?>
  <form method="post" data-confirm="Batalkan draft pembelian ini? Item yang sudah ditambahkan tidak akan mempengaruhi stok.">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="cancel_draft">
    <button type="submit" class="btn btn-danger">Batalkan Draft</button>
  </form>
  <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/purchases/index.php">Kembali ke Daftar</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
