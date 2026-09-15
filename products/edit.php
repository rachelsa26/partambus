<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Edit Produk';
$backUrl = APP_BASE_PATH . '/products/index.php';
$backLabel = 'Kembali ke Daftar Produk';

$id = (int) ($_GET['id'] ?? 0);
$justSaved = isset($_GET['saved']);
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    flash_set('error', 'Produk tidak ditemukan.');
    redirect('/products/index.php');
}
// DECIMAL columns come back from PDO as strings with trailing zeros
// ("15000.0000") — trimmed once here for a clean form value; the
// POST-error repopulate path below overwrites this with the raw text the
// user actually typed instead, so this only affects the fresh-GET render.
$product['current_wac'] = $product['current_wac'] !== null
    ? rtrim(rtrim(number_format((float) $product['current_wac'], 4, '.', ''), '0'), '.')
    : null;

$unitStmt = $pdo->prepare('SELECT * FROM product_units WHERE product_id = ? ORDER BY is_base DESC, unit_name');
$unitStmt->execute([$id]);
$originalUnits = $unitStmt->fetchAll();

$usedUnitIds = [];
foreach ($originalUnits as $u) {
    $usedCheck = $pdo->prepare(
        '(SELECT 1 FROM purchase_items pi JOIN purchases pu ON pu.id = pi.purchase_id
          WHERE pi.unit_id = ? AND pu.status != "cancelled_draft" LIMIT 1)
         UNION (SELECT 1 FROM sale_items WHERE unit_id = ? LIMIT 1)
         LIMIT 1'
    );
    $usedCheck->execute([$u['id'], $u['id']]);
    if ($usedCheck->fetch()) {
        $usedUnitIds[(int) $u['id']] = true;
    }
}

$movementCheck = $pdo->prepare('SELECT 1 FROM stock_movements WHERE product_id = ? LIMIT 1');
$movementCheck->execute([$id]);
$hasStockMovements = (bool) $movementCheck->fetch();

$unitsList = get_active_units($pdo);
$errors = [];

if (is_post()) {
    verify_csrf();

    $newCode = post('code');
    $newBarcode = post('barcode');
    $newName = post('name');
    $newThreshold = post('low_stock_threshold_base', '0');
    $newActive = isset($_POST['active']) ? 1 : 0;
    $codeNormalized = normalize_code($newCode);

    $baseUnitError = null;
    $newBaseUnitName = resolve_unit_input($pdo, post('base_unit_name'), post('base_unit_name_other'), 'Satuan dasar', $baseUnitError)
        ?? $product['base_unit_name'];
    if ($baseUnitError) {
        $errors[] = $baseUnitError;
    }

    // ---- manual Harga Modal (WAC) override ----
    // Pre-filled with the current value (or blank when unknown), so a
    // submission left untouched round-trips to the same value and a
    // deliberately emptied field means "clear back to unknown" — no
    // separate checkbox needed to tell "didn't touch it" from "cleared it".
    $wacRaw = trim(post('current_wac', ''));
    $oldWac = $product['current_wac'] !== null ? (float) $product['current_wac'] : null;
    $newWac = null;
    if ($wacRaw !== '') {
        if (!is_numeric($wacRaw) || (float) $wacRaw < 0) {
            $errors[] = 'Harga Modal harus angka >= 0.';
            $newWac = $oldWac;
        } else {
            $newWac = (float) $wacRaw;
        }
    }
    $wacChanged = ($oldWac === null) !== ($newWac === null)
        || ($oldWac !== null && $newWac !== null && abs($oldWac - $newWac) > 0.00005);

    if ($newCode === '') {
        $errors[] = 'Kode produk wajib diisi.';
    }
    if ($newName === '') {
        $errors[] = 'Nama produk wajib diisi.';
    }
    if (!ctype_digit($newThreshold)) {
        $errors[] = 'Batas stok rendah harus angka bulat >= 0.';
    }
    if ($hasStockMovements && strcasecmp($newBaseUnitName, $product['base_unit_name']) !== 0) {
        $errors[] = 'Satuan dasar tidak bisa diubah karena produk sudah punya pergerakan stok.';
        $newBaseUnitName = $product['base_unit_name'];
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM products WHERE code_normalized = ? AND id != ?');
        $check->execute([$codeNormalized, $id]);
        if ($check->fetch()) {
            $errors[] = 'Kode produk "' . $newCode . '" sudah dipakai produk lain.';
        }
    }
    if (!$errors && $newBarcode !== '') {
        $check = $pdo->prepare('SELECT id FROM products WHERE barcode = ? AND id != ?');
        $check->execute([$newBarcode, $id]);
        if ($check->fetch()) {
            $errors[] = 'Barcode "' . $newBarcode . '" sudah dipakai produk lain.';
        }
    }

    // ---- existing units ----
    $unitNamesSeen = [strtolower($newBaseUnitName)];
    $existingUnitUpdates = [];
    $unitIdsToDelete = [];
    foreach ($originalUnits as $u) {
        $uid = (int) $u['id'];
        $submitted = $_POST['existing_units'][$uid] ?? null;
        if ($submitted === null) {
            continue;
        }

        // A unit marked for deletion (via the row's "Hapus" menu action)
        // skips the rest of this row entirely — nothing else about it is
        // validated/updated. The base unit and any unit already referenced
        // by a real sale/purchase can never be deleted (checked again here
        // even though the UI already hides "Hapus" for those, since this is
        // the actual authority — the UI hiding it is just a convenience).
        if (!empty($submitted['delete'])) {
            if ($u['is_base']) {
                $errors[] = 'Satuan dasar "' . $u['unit_name'] . '" tidak bisa dihapus.';
            } elseif (isset($usedUnitIds[$uid])) {
                $errors[] = 'Satuan "' . $u['unit_name'] . '" sudah dipakai transaksi, tidak bisa dihapus.';
            } else {
                $unitIdsToDelete[] = $uid;
            }
            continue;
        }

        $selectVal = trim((string) ($submitted['unit_name'] ?? ''));
        $otherVal = trim((string) ($submitted['unit_name_other'] ?? ''));
        $rowUnitError = null;
        $unitName = $selectVal !== ''
            ? (resolve_unit_input($pdo, $selectVal, $otherVal, 'Unit "' . $u['unit_name'] . '"', $rowUnitError) ?? $u['unit_name'])
            : $u['unit_name'];
        if ($rowUnitError) {
            $errors[] = $rowUnitError;
        }
        $conversion = trim((string) ($submitted['conversion_factor'] ?? (string) $u['conversion_factor']));
        $canSell = isset($submitted['can_sell']);
        $canPurchase = isset($submitted['can_purchase']);
        $active = isset($submitted['active']);
        $sellingPrice = trim((string) ($submitted['selling_price'] ?? ''));

        $isUsed = isset($usedUnitIds[$uid]);
        $identityChanged = strcasecmp($unitName, $u['unit_name']) !== 0
            || (int) $conversion !== (int) $u['conversion_factor'];

        if ($u['is_base']) {
            $unitName = $newBaseUnitName;
            $conversion = '1';
        } elseif ($isUsed && $identityChanged) {
            $errors[] = 'Unit "' . $u['unit_name'] . '" sudah dipakai transaksi, nama/konversi tidak bisa diubah. Buat unit baru sebagai gantinya.';
            $unitName = $u['unit_name'];
            $conversion = (string) $u['conversion_factor'];
        } elseif (!ctype_digit($conversion) || (int) $conversion <= 0) {
            $errors[] = 'Faktor konversi unit "' . $u['unit_name'] . '" harus bilangan bulat positif.';
        }

        $lower = strtolower($unitName);
        if (!$u['is_base']) {
            if (in_array($lower, $unitNamesSeen, true)) {
                $errors[] = 'Nama unit "' . $unitName . '" duplikat.';
            }
            $unitNamesSeen[] = $lower;
        }

        if ($canSell && ($sellingPrice === '' || !is_numeric($sellingPrice) || (float) $sellingPrice < 0)) {
            $errors[] = 'Harga jual unit "' . $unitName . '" wajib diisi (>= 0) karena ditandai bisa dijual.';
        }

        $existingUnitUpdates[$uid] = [
            'unit_name' => $unitName,
            'conversion_factor' => (int) $conversion,
            'can_sell' => $canSell,
            'can_purchase' => $canPurchase,
            'active' => $active,
            'selling_price' => $canSell ? (is_numeric($sellingPrice) ? (float) $sellingPrice : null) : null,
            'original' => $u,
        ];
    }

    // ---- new units ----
    $newUnits = [];
    foreach ($_POST['units'] ?? [] as $row) {
        $selectVal = trim((string) ($row['unit_name'] ?? ''));
        $otherVal = trim((string) ($row['unit_name_other'] ?? ''));
        if ($selectVal === '') {
            continue;
        }
        $rowUnitError = null;
        $unitName = resolve_unit_input($pdo, $selectVal, $otherVal, 'Unit tambahan', $rowUnitError) ?? $selectVal;
        if ($rowUnitError) {
            $errors[] = $rowUnitError;
        }
        $conversion = trim((string) ($row['conversion_factor'] ?? ''));
        $canSell = isset($row['can_sell']);
        $canPurchase = isset($row['can_purchase']);
        $sellingPrice = trim((string) ($row['selling_price'] ?? ''));

        $lower = strtolower($unitName);
        if (in_array($lower, $unitNamesSeen, true)) {
            $errors[] = 'Nama unit "' . $unitName . '" duplikat.';
        }
        $unitNamesSeen[] = $lower;

        if (!ctype_digit($conversion) || (int) $conversion <= 0) {
            $errors[] = 'Faktor konversi unit "' . $unitName . '" harus bilangan bulat positif.';
        }
        if ($canSell && ($sellingPrice === '' || !is_numeric($sellingPrice) || (float) $sellingPrice < 0)) {
            $errors[] = 'Harga jual unit "' . $unitName . '" wajib diisi (>= 0).';
        }

        $newUnits[] = [
            'unit_name' => $unitName,
            'conversion_factor' => (int) $conversion,
            'can_sell' => $canSell,
            'can_purchase' => $canPurchase,
            'selling_price' => $canSell && is_numeric($sellingPrice) ? (float) $sellingPrice : null,
        ];
    }

    // If every unit ends up inactive, the product can't actually be sold no
    // matter what "Produk aktif" says — force it inactive too so the product
    // list never shows "Aktif" for something with zero sellable units.
    if (!$errors && $newActive) {
        $hasActiveUnit = count($newUnits) > 0; // new units are always inserted as active=1
        if (!$hasActiveUnit) {
            foreach ($originalUnits as $u) {
                $uid = (int) $u['id'];
                $finalActive = isset($existingUnitUpdates[$uid]) ? $existingUnitUpdates[$uid]['active'] : (bool) $u['active'];
                if ($finalActive) {
                    $hasActiveUnit = true;
                    break;
                }
            }
        }
        if (!$hasActiveUnit) {
            $newActive = 0;
            flash_set('warning', 'Produk otomatis dinonaktifkan karena tidak ada satuan yang aktif (tidak bisa dijual sama sekali).');
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $before = [
                'code' => $product['code'],
                'barcode' => $product['barcode'],
                'name' => $product['name'],
                'base_unit_name' => $product['base_unit_name'],
                'low_stock_threshold_base' => (int) $product['low_stock_threshold_base'],
                'active' => (int) $product['active'],
            ];

            $stmt = $pdo->prepare(
                'UPDATE products SET code = ?, code_normalized = ?, barcode = ?, name = ?, base_unit_name = ?,
                 low_stock_threshold_base = ?, active = ?, current_wac = ?, updated_by = ? WHERE id = ?'
            );
            $stmt->execute([
                $newCode,
                $codeNormalized,
                $newBarcode !== '' ? $newBarcode : null,
                $newName,
                $newBaseUnitName,
                (int) $newThreshold,
                $newActive,
                $newWac,
                current_user()['id'],
                $id,
            ]);

            $afterForAudit = [
                'code' => $newCode,
                'barcode' => $newBarcode !== '' ? $newBarcode : null,
                'name' => $newName,
                'base_unit_name' => $newBaseUnitName,
                'low_stock_threshold_base' => (int) $newThreshold,
                'active' => $newActive,
            ];
            if (audit_has_real_change($before, $afterForAudit)) {
                log_audit($pdo, 'product_updated', 'product', $id, $before, $afterForAudit);
            }
            if ($wacChanged) {
                log_audit(
                    $pdo, 'wac_manual_adjustment', 'product', $id,
                    ['current_wac' => $oldWac], ['current_wac' => $newWac],
                    'Koreksi manual dari halaman edit produk'
                );
            }

            $updateUnitStmt = $pdo->prepare(
                'UPDATE product_units SET unit_name = ?, conversion_factor = ?, can_sell = ?, can_purchase = ?, selling_price = ?, active = ?
                 WHERE id = ?'
            );
            foreach ($existingUnitUpdates as $uid => $u) {
                $orig = $u['original'];
                $updateUnitStmt->execute([
                    $u['unit_name'],
                    $u['conversion_factor'],
                    $u['can_sell'] ? 1 : 0,
                    $u['can_purchase'] ? 1 : 0,
                    $u['selling_price'],
                    $u['active'] ? 1 : 0,
                    $uid,
                ]);

                $origPrice = $orig['selling_price'] !== null ? (float) $orig['selling_price'] : null;
                if ($origPrice !== $u['selling_price']) {
                    log_audit($pdo, 'price_changed', 'product_unit', $uid, ['selling_price' => $origPrice], ['selling_price' => $u['selling_price']]);
                }
            }

            if ($unitIdsToDelete) {
                $unitsById = [];
                foreach ($originalUnits as $u) {
                    $unitsById[(int) $u['id']] = $u;
                }
                $deleteUnitStmt = $pdo->prepare('DELETE FROM product_units WHERE id = ?');
                foreach ($unitIdsToDelete as $uid) {
                    $deleteUnitStmt->execute([$uid]);
                    log_audit(
                        $pdo, 'unit_deleted', 'product_unit', $uid,
                        ['unit_name' => $unitsById[$uid]['unit_name'], 'conversion_factor' => (int) $unitsById[$uid]['conversion_factor']],
                        null
                    );
                }
            }

            $insertUnitStmt = $pdo->prepare(
                'INSERT INTO product_units (product_id, unit_name, conversion_factor, is_base, can_sell, can_purchase, selling_price, active)
                 VALUES (?, ?, ?, 0, ?, ?, ?, 1)'
            );
            foreach ($newUnits as $u) {
                $insertUnitStmt->execute([
                    $id,
                    $u['unit_name'],
                    $u['conversion_factor'],
                    $u['can_sell'] ? 1 : 0,
                    $u['can_purchase'] ? 1 : 0,
                    $u['selling_price'],
                ]);
                log_audit($pdo, 'unit_created', 'product_unit', (int) $pdo->lastInsertId(), null, $u);
            }

            $pdo->commit();
            flash_set('success', 'Produk berhasil diperbarui.');
            redirect('/products/edit.php?id=' . $id . '&saved=1');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Gagal menyimpan perubahan: ' . $e->getMessage();
        }
    }

    // repopulate for re-render on error — current_wac keeps the raw text the
    // user typed (not $newWac, which falls back to the old value when that
    // text failed validation) so a typo stays visible to fix instead of
    // silently reverting.
    $product = array_merge($product, [
        'code' => $newCode,
        'barcode' => $newBarcode,
        'name' => $newName,
        'base_unit_name' => $newBaseUnitName,
        'low_stock_threshold_base' => $newThreshold,
        'active' => $newActive,
        'current_wac' => $wacRaw !== '' ? $wacRaw : null,
    ]);
}

$topbarSubtitle = 'Perbarui informasi produk, stok, dan unit penjualan.';

require __DIR__ . '/../includes/header.php';
?>

<div class="edit-product-tabs">
  <a class="edit-product-tab" href="<?= APP_BASE_PATH ?>/inventory/initial_stock.php?product_id=<?= $id ?>"><?= partambus_icon('save', 15) ?> Catat Stok Awal</a>
  <a class="edit-product-tab" href="<?= APP_BASE_PATH ?>/inventory/adjustment.php?product_id=<?= $id ?>"><?= partambus_icon('refresh', 15) ?> Penyesuaian Stok</a>
  <a class="edit-product-tab" href="<?= APP_BASE_PATH ?>/inventory/movements.php?product_id=<?= $id ?>"><?= partambus_icon('calendar', 15) ?> Riwayat Stok</a>
</div>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate data-loading-message="Menyimpan perubahan...">
  <?= csrf_field() ?>

  <div class="edit-product-grid">
    <div class="card">
      <div class="card-title-row">
        <span class="card-title-icon-box"><?= partambus_icon('box', 18) ?></span>
        <strong style="font-size:16px">Informasi Produk</strong>
      </div>

      <div class="form-row" style="margin-top:16px">
        <div class="form-group">
          <label for="code">Kode Produk</label>
          <input type="text" id="code" name="code" value="<?= e($product['code']) ?>" required>
        </div>
        <div class="form-group">
          <label for="barcode">Barcode</label>
          <input type="text" id="barcode" name="barcode" value="<?= e((string) $product['barcode']) ?>">
        </div>
      </div>

      <div class="form-group">
        <label for="name">Nama Produk</label>
        <input type="text" id="name" name="name" value="<?= e($product['name']) ?>" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="base_unit_name">Satuan Dasar</label>
          <?= render_unit_select($unitsList, $product['base_unit_name'], 'base_unit_name', 'base_unit_name_other', $hasStockMovements) ?>
          <?php if ($hasStockMovements): ?>
            <p class="form-hint"><?= partambus_icon('info', 13) ?> Terkunci karena sudah ada pergerakan stok.</p>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label for="low_stock_threshold_base">Batas Stok Rendah</label>
          <input type="number" id="low_stock_threshold_base" name="low_stock_threshold_base" value="<?= e((string) $product['low_stock_threshold_base']) ?>" min="0" step="1">
        </div>
      </div>

      <div class="form-group edit-product-active-row">
        <label class="toggle-switch">
          <input type="checkbox" name="active" value="1" <?= (int) $product['active'] === 1 ? 'checked' : '' ?>>
          <span class="toggle-switch-track"></span>
        </label>
        <div>
          <label style="margin:0">Produk aktif</label>
          <p class="form-hint" style="margin-top:2px">Mengontrol seluruh produk ini (semua satuannya). Tidak sama dengan kolom "Satuan Aktif" di tabel Unit Produk di bawah, yang hanya mengontrol satu satuan saja.</p>
        </div>
      </div>
    </div>

    <div class="card" style="background:var(--color-warning-bg);border-color:var(--color-warning-accent)">
      <div class="card-title-row">
        <span class="card-title-icon-box card-title-icon-box-warning"><?= partambus_icon('warning', 18) ?></span>
        <strong style="font-size:16px">Sesuaikan Harga Modal</strong>
      </div>
      <p class="edit-product-wac-note">Catatan: Harga Modal seharusnya otomatis terhitung dari transaksi Pembelian. Gunakan ini hanya untuk koreksi data awal.</p>
      <div class="form-group" style="margin-bottom:0">
        <label for="current_wac">Harga Modal (Rp per <?= e($product['base_unit_name']) ?>)</label>
        <input type="number" id="current_wac" name="current_wac" value="<?= e((string) $product['current_wac']) ?>" min="0" step="0.01" placeholder="Belum diketahui">
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-title-row">
      <span class="card-title-icon-box"><?= partambus_icon('layers', 18) ?></span>
      <strong style="font-size:16px">Unit Produk</strong>
    </div>
    <table style="margin-top:12px">
      <thead>
        <tr><th>Nama</th><th>Konversi</th><th>Dijual</th><th>Dibeli</th><th>Harga Jual</th><th>Satuan Aktif</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($originalUnits as $u): $uid = (int) $u['id']; $isUsed = isset($usedUnitIds[$uid]); ?>
        <tr class="unit-row-existing">
          <td>
            <?= render_unit_select($unitsList, $u['unit_name'], "existing_units[$uid][unit_name]", "existing_units[$uid][unit_name_other]", $u['is_base'] || $isUsed, 'width:140px') ?>
            <?php if ($u['is_base']): ?><span class="badge badge-info">base</span><?php endif; ?>
          </td>
          <td>
            <input type="number" name="existing_units[<?= $uid ?>][conversion_factor]" value="<?= (int) $u['conversion_factor'] ?>" min="1" step="1"
              <?= ($u['is_base'] || $isUsed) ? 'readonly' : '' ?> style="width:70px">
          </td>
          <td><input type="checkbox" name="existing_units[<?= $uid ?>][can_sell]" value="1" <?= $u['can_sell'] ? 'checked' : '' ?>></td>
          <td><input type="checkbox" name="existing_units[<?= $uid ?>][can_purchase]" value="1" <?= $u['can_purchase'] ? 'checked' : '' ?>></td>
          <td><input type="number" name="existing_units[<?= $uid ?>][selling_price]" value="<?= e((string) $u['selling_price']) ?>" min="0" step="1" style="width:100px"></td>
          <td><input type="checkbox" name="existing_units[<?= $uid ?>][active]" value="1" <?= $u['active'] ? 'checked' : '' ?>></td>
          <td>
            <input type="checkbox" name="existing_units[<?= $uid ?>][delete]" value="1" class="unit-delete-flag hidden">
            <?php if ($u['is_base']): ?>
              <button type="button" class="action-btn action-btn-danger" disabled title="Satuan dasar tidak bisa dihapus" aria-label="Satuan dasar tidak bisa dihapus">
                <?= partambus_icon('trash', 13) ?>
              </button>
            <?php elseif ($isUsed): ?>
              <button type="button" class="action-btn action-btn-danger" disabled title="Sudah dipakai transaksi, tidak bisa dihapus" aria-label="Sudah dipakai transaksi, tidak bisa dihapus">
                <?= partambus_icon('trash', 13) ?>
              </button>
            <?php else: ?>
              <button type="button" class="action-btn action-btn-danger" data-delete-unit-row aria-label="Hapus satuan" title="Hapus satuan">
                <?= partambus_icon('trash', 13) ?>
              </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($isUsed): ?>
        <tr><td colspan="7" class="text-muted" style="font-size:11px;padding-top:0">Sudah dipakai transaksi — nama &amp; konversi terkunci.</td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div id="unit-rows" data-next-index="0" style="margin-top:16px"></div>
    <button type="button" class="btn btn-outline-primary btn-small" data-add-unit-row><?= partambus_icon('plus', 14) ?> Tambah Unit Baru</button>
  </div>

  <div class="btn-row">
    <button type="submit" class="btn"><?= partambus_icon('save', 15) ?> Simpan Perubahan</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/products/index.php">&larr; Kembali</a>
  </div>
</form>

<template id="unit-row-template">
  <div class="unit-row">
    <div class="form-group unit-col-name">
      <label>Nama Unit</label>
      <?= render_unit_select($unitsList, '', 'units[__INDEX__][unit_name]', 'units[__INDEX__][unit_name_other]') ?>
    </div>
    <div class="form-group unit-col-num">
      <label>Konversi ke Satuan Dasar</label>
      <input type="number" name="units[__INDEX__][conversion_factor]" min="1" step="1" placeholder="mis. 10">
    </div>
    <div class="form-group unit-col-check">
      <label class="checkbox-inline"><input type="checkbox" name="units[__INDEX__][can_sell]" value="1"> Dijual</label>
    </div>
    <div class="form-group unit-col-check">
      <label class="checkbox-inline"><input type="checkbox" name="units[__INDEX__][can_purchase]" value="1"> Dibeli</label>
    </div>
    <div class="form-group unit-col-num">
      <label>Harga Jual</label>
      <input type="number" name="units[__INDEX__][selling_price]" min="0" step="1">
    </div>
    <div class="unit-col-remove">
      <button type="button" class="btn btn-secondary btn-small" data-remove-unit-row>Hapus</button>
    </div>
  </div>
</template>

<?php if ($justSaved): ?>
<div class="modal-overlay" id="edit-success-modal">
  <div class="modal-box">
    <p>✅ Produk berhasil diperbarui!</p>
    <div class="btn-row">
      <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/products/index.php">Kembali ke Daftar Produk</a>
      <button type="button" class="btn" data-modal-cancel>Tetap di Halaman Ini</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
