<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Tambah Produk';
$backUrl = APP_BASE_PATH . '/products/index.php';
$backLabel = 'Kembali ke Daftar Produk';

$unitsList = get_active_units($pdo);
$defaultUnitCode = get_setting($pdo, 'default_unit_code', '');

$errors = [];
$form = [
    'code' => '',
    'barcode' => '',
    'name' => '',
    'base_unit_select' => $defaultUnitCode ?? '',
    'base_unit_other' => '',
    'low_stock_threshold_base' => '0',
    'base_can_sell' => false,
    'base_can_purchase' => true,
    'base_selling_price' => '',
];
$extraUnits = [];

if (is_post()) {
    verify_csrf();

    $form['code'] = post('code');
    $form['barcode'] = post('barcode');
    $form['name'] = post('name');
    $form['base_unit_select'] = post('base_unit_name');
    $form['base_unit_other'] = post('base_unit_name_other');
    $form['low_stock_threshold_base'] = post('low_stock_threshold_base', '0');
    $form['base_can_sell'] = isset($_POST['base_can_sell']);
    $form['base_can_purchase'] = isset($_POST['base_can_purchase']);
    $form['base_selling_price'] = post('base_selling_price');

    $baseUnitError = null;
    $baseUnitName = resolve_unit_input($pdo, $form['base_unit_select'], $form['base_unit_other'], 'Satuan dasar', $baseUnitError);
    if ($baseUnitError) {
        $errors[] = $baseUnitError;
    }

    $extraUnits = [];
    foreach ($_POST['units'] ?? [] as $row) {
        $selectVal = trim((string) ($row['unit_name'] ?? ''));
        $otherVal = trim((string) ($row['unit_name_other'] ?? ''));
        if ($selectVal === '') {
            continue;
        }

        $unitError = null;
        $resolvedUnitName = resolve_unit_input($pdo, $selectVal, $otherVal, 'Unit tambahan', $unitError);
        if ($unitError) {
            $errors[] = $unitError;
        }

        $extraUnits[] = [
            'unit_name' => $resolvedUnitName ?? ($selectVal === '__other__' ? $otherVal : $selectVal),
            'conversion_factor' => trim((string) ($row['conversion_factor'] ?? '')),
            'can_sell' => isset($row['can_sell']),
            'can_purchase' => isset($row['can_purchase']),
            'selling_price' => trim((string) ($row['selling_price'] ?? '')),
        ];
    }

    $codeNormalized = normalize_code($form['code']);

    if ($form['code'] === '') {
        $errors[] = 'Kode produk wajib diisi.';
    }
    if ($form['name'] === '') {
        $errors[] = 'Nama produk wajib diisi.';
    }
    if (!ctype_digit($form['low_stock_threshold_base'])) {
        $errors[] = 'Batas stok rendah harus berupa angka bulat >= 0.';
    }

    if ($form['base_can_sell'] && ($form['base_selling_price'] === '' || !is_numeric($form['base_selling_price']) || (float) $form['base_selling_price'] < 0)) {
        $errors[] = 'Harga jual satuan dasar wajib diisi (>= 0) karena satuan dasar ditandai bisa dijual.';
    }

    $unitNamesSeen = $baseUnitName !== null ? [strtolower($baseUnitName)] : [];
    $hasSellableUnit = $form['base_can_sell'];

    foreach ($extraUnits as $i => $row) {
        $lower = strtolower($row['unit_name']);
        if (in_array($lower, $unitNamesSeen, true)) {
            $errors[] = 'Nama unit "' . $row['unit_name'] . '" duplikat.';
        }
        $unitNamesSeen[] = $lower;

        if (!ctype_digit($row['conversion_factor']) || (int) $row['conversion_factor'] <= 0) {
            $errors[] = 'Faktor konversi untuk unit "' . $row['unit_name'] . '" harus bilangan bulat positif.';
        }
        if ($row['can_sell']) {
            $hasSellableUnit = true;
            if ($row['selling_price'] === '' || !is_numeric($row['selling_price']) || (float) $row['selling_price'] < 0) {
                $errors[] = 'Harga jual untuk unit "' . $row['unit_name'] . '" wajib diisi (>= 0).';
            }
        }
    }

    if (!$hasSellableUnit) {
        $errors[] = 'Produk harus punya minimal satu unit yang bisa dijual (base unit atau unit tambahan).';
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM products WHERE code_normalized = ?');
        $check->execute([$codeNormalized]);
        if ($check->fetch()) {
            $errors[] = 'Kode produk "' . $form['code'] . '" sudah dipakai produk lain.';
        }
    }
    if (!$errors && $form['barcode'] !== '') {
        $check = $pdo->prepare('SELECT id FROM products WHERE barcode = ?');
        $check->execute([$form['barcode']]);
        if ($check->fetch()) {
            $errors[] = 'Barcode "' . $form['barcode'] . '" sudah dipakai produk lain.';
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO products (code, code_normalized, barcode, name, base_unit_name, low_stock_threshold_base, active, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmt->execute([
                $form['code'],
                $codeNormalized,
                $form['barcode'] !== '' ? $form['barcode'] : null,
                $form['name'],
                $baseUnitName,
                (int) $form['low_stock_threshold_base'],
                current_user()['id'],
                current_user()['id'],
            ]);
            $productId = (int) $pdo->lastInsertId();

            $unitStmt = $pdo->prepare(
                'INSERT INTO product_units (product_id, unit_name, conversion_factor, is_base, can_sell, can_purchase, selling_price, active)
                 VALUES (?, ?, 1, 1, ?, ?, ?, 1)'
            );
            $unitStmt->execute([
                $productId,
                $baseUnitName,
                $form['base_can_sell'] ? 1 : 0,
                $form['base_can_purchase'] ? 1 : 0,
                $form['base_can_sell'] ? (float) $form['base_selling_price'] : null,
            ]);

            $extraStmt = $pdo->prepare(
                'INSERT INTO product_units (product_id, unit_name, conversion_factor, is_base, can_sell, can_purchase, selling_price, active)
                 VALUES (?, ?, ?, 0, ?, ?, ?, 1)'
            );
            foreach ($extraUnits as $row) {
                $extraStmt->execute([
                    $productId,
                    $row['unit_name'],
                    (int) $row['conversion_factor'],
                    $row['can_sell'] ? 1 : 0,
                    $row['can_purchase'] ? 1 : 0,
                    $row['can_sell'] ? (float) $row['selling_price'] : null,
                ]);
            }

            log_audit($pdo, 'product_created', 'product', $productId, null, [
                'code' => $form['code'],
                'name' => $form['name'],
                'base_unit_name' => $baseUnitName,
                'extra_units' => count($extraUnits),
            ]);

            $pdo->commit();
            flash_set('success', 'Produk "' . $form['name'] . '" berhasil dibuat.');
            redirect('/products/index.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Gagal menyimpan produk: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:720px">
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate data-loading-message="Menyimpan produk...">
  <?= csrf_field() ?>

  <div class="form-row">
    <div class="form-group">
      <label for="code">Kode Produk</label>
      <input type="text" id="code" name="code" value="<?= e($form['code']) ?>" required autofocus>
      <p class="form-hint">Boleh manual, misal GL001. Wajib unik (tidak case-sensitive).</p>
    </div>
    <div class="form-group">
      <label for="barcode">Barcode (opsional)</label>
      <input type="text" id="barcode" name="barcode" value="<?= e($form['barcode']) ?>">
    </div>
  </div>

  <div class="form-group">
    <label for="name">Nama Produk</label>
    <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" required>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label for="base_unit_name">Satuan Dasar (base unit)</label>
      <?= render_unit_select($unitsList, $form['base_unit_select'] === '__other__' ? '' : $form['base_unit_select'], 'base_unit_name', 'base_unit_name_other') ?>
      <p class="form-hint">Semua stok dihitung dalam satuan ini. Tidak bisa diubah setelah ada pergerakan stok.</p>
    </div>
    <div class="form-group">
      <label for="low_stock_threshold_base">Batas Stok Rendah (dalam satuan dasar)</label>
      <input type="number" id="low_stock_threshold_base" name="low_stock_threshold_base" value="<?= e($form['low_stock_threshold_base']) ?>" min="0" step="1">
    </div>
  </div>

  <div class="card" style="background:#fafafa">
    <strong>Satuan Dasar sebagai satuan jual/beli?</strong>
    <div class="form-row" style="margin-top:10px">
      <label class="checkbox-inline"><input type="checkbox" name="base_can_sell" value="1" <?= $form['base_can_sell'] ? 'checked' : '' ?>> Bisa dijual</label>
      <label class="checkbox-inline"><input type="checkbox" name="base_can_purchase" value="1" <?= $form['base_can_purchase'] ? 'checked' : '' ?>> Bisa dibeli</label>
    </div>
    <div class="form-group" style="margin-top:10px">
      <label for="base_selling_price">Harga Jual per Satuan Dasar</label>
      <input type="number" id="base_selling_price" name="base_selling_price" value="<?= e($form['base_selling_price']) ?>" min="0" step="1">
    </div>
  </div>

  <div class="card" style="background:#fafafa">
    <strong>Unit Tambahan (opsional)</strong>
    <p class="form-hint">Contoh: 1 slop = 10 bungkus. Harga per unit tidak harus proporsional.</p>
    <div id="unit-rows" data-next-index="0"></div>
    <button type="button" class="btn btn-secondary btn-small" data-add-unit-row>+ Tambah Unit</button>
  </div>

  <div class="btn-row">
    <button type="submit" class="btn">Simpan Produk</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/products/index.php">Batal</a>
  </div>
</form>
</div>

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

<?php require __DIR__ . '/../includes/footer.php'; ?>
