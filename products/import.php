<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_role(['owner']);
$pageTitle = 'Import Produk Massal';
$backUrl = APP_BASE_PATH . '/products/index.php';
$backLabel = 'Kembali ke Daftar Produk';

$errors = [];
$unresolvedStep = null;
$preview = null;
$results = null;

$defaultLowStockThreshold = (int) get_setting($pdo, 'low_stock_default_threshold', '5');
$unitsList = get_active_units($pdo);

/**
 * Applies a unit resolution map to every row's raw "unit" text, replacing it
 * with a canonical unit code. Recognized units resolve via the reference
 * table directly; everything else must already be covered by $resolutionMap
 * (built from the "resolve unknown units" step).
 *
 * @param array<int, array{mapped: array, errors: string[]}> $mappedRows
 * @param array<string, string> $resolutionMap normalized raw text => resolved code
 */
function apply_unit_resolution(PDO $pdo, array $mappedRows, array $resolutionMap): array
{
    foreach ($mappedRows as &$entry) {
        $raw = $entry['mapped']['unit'];
        if ($raw === '') {
            continue;
        }
        $recognized = find_unit_by_text($pdo, $raw);
        if ($recognized) {
            $entry['mapped']['unit'] = $recognized['code'];
            continue;
        }
        $key = strtoupper(trim($raw));
        if (isset($resolutionMap[$key])) {
            $entry['mapped']['unit'] = $resolutionMap[$key];
        }
        // else: left as raw text — shouldn't happen if the resolve-units step covered every value.
    }
    unset($entry);

    return $mappedRows;
}

/** @return string[] unique raw unit texts (normalized casing/trim) that don't match the reference list */
function find_unresolved_units(PDO $pdo, array $mappedRows): array
{
    $seen = [];
    $unresolved = [];
    foreach ($mappedRows as $entry) {
        $raw = trim($entry['mapped']['unit']);
        if ($raw === '') {
            continue;
        }
        $key = strtoupper($raw);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        if (!find_unit_by_text($pdo, $raw)) {
            $unresolved[] = $raw;
        }
    }
    return $unresolved;
}

/** Parses+maps every row of the (already unit-resolved) file, without writing to the database. */
function build_grouped_preview(PDO $pdo, string $filePath, string $extension, string $tempName, array $resolutionMap): array
{
    $data = read_import_spreadsheet($filePath, $extension);
    $resolvedColumns = resolve_import_columns($data['headers']);

    $requiredFields = ['code' => 'Kode Item', 'unit' => 'Satuan', 'conversion' => 'Konversi'];
    $missingRequired = [];
    foreach ($requiredFields as $field => $label) {
        if (!isset($resolvedColumns[$field])) {
            $missingRequired[] = $label;
        }
    }
    if ($missingRequired) {
        throw new RuntimeException(
            'Kolom wajib tidak ditemukan di file: ' . implode(', ', $missingRequired) . '. Pastikan header kolom sesuai format yang diminta.'
        );
    }

    $mappedRows = [];
    foreach ($data['rows'] as $rawRow) {
        $mapped = map_import_row($rawRow, $resolvedColumns);
        $mappedRows[] = ['mapped' => $mapped, 'errors' => validate_import_row($mapped)];
    }
    $mappedRows = apply_unit_resolution($pdo, $mappedRows, $resolutionMap);

    $groups = group_import_rows($mappedRows);

    $productCount = 0;
    $duplicateCount = 0;
    $failedCount = 0;
    foreach ($groups as $key => $group) {
        if ($group['group_errors']) {
            $failedCount++;
            continue;
        }
        $baseRow = $group['rows'][$group['base_index']]['mapped'];
        $existing = find_existing_product_for_import(
            $pdo, normalize_code($baseRow['code']), $baseRow['barcode'] !== '' ? $baseRow['barcode'] : null
        );
        $groups[$key]['existing'] = $existing;
        if ($existing) {
            $duplicateCount++;
        } else {
            $productCount++;
        }
    }

    $recognizedHeaderTexts = array_values($resolvedColumns);
    $unknownHeaders = array_diff($data['headers'], $recognizedHeaderTexts);

    return [
        'temp_name' => $tempName,
        'unknown_headers' => array_values($unknownHeaders),
        'has_category_column' => isset($resolvedColumns['category']),
        'has_wholesale_column' => isset($resolvedColumns['wholesale_price']),
        'total_rows' => count($data['rows']),
        'group_count' => count($groups),
        'product_count' => $productCount,
        'duplicate_count' => $duplicateCount,
        'failed_count' => $failedCount,
        'groups' => $groups,
    ];
}

if (is_post()) {
    verify_csrf();
    $formAction = post('form_action');

    if ($formAction === 'upload') {
        if (empty($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            $errors[] = 'Pilih file terlebih dahulu.';
        } else {
            $originalName = (string) $_FILES['import_file']['name'];
            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
                $errors[] = 'Format file harus .xlsx atau .csv.';
            } else {
                if (!is_dir(IMPORT_DIR)) {
                    mkdir(IMPORT_DIR, 0755, true);
                }
                $tempName = 'import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $tempPath = IMPORT_DIR . '/' . $tempName;

                if (!move_uploaded_file($_FILES['import_file']['tmp_name'], $tempPath)) {
                    $errors[] = 'Gagal menyimpan file yang diupload.';
                } else {
                    try {
                        $data = read_import_spreadsheet($tempPath, $extension);
                        $resolvedColumns = resolve_import_columns($data['headers']);
                        $mappedRows = [];
                        foreach ($data['rows'] as $rawRow) {
                            $mapped = map_import_row($rawRow, $resolvedColumns);
                            $mappedRows[] = ['mapped' => $mapped, 'errors' => validate_import_row($mapped)];
                        }
                        $unresolvedUnits = find_unresolved_units($pdo, $mappedRows);

                        if ($unresolvedUnits) {
                            $unresolvedStep = ['temp_name' => $tempName, 'units' => $unresolvedUnits];
                        } else {
                            $preview = build_grouped_preview($pdo, $tempPath, $extension, $tempName, []);
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'Gagal membaca file: ' . $e->getMessage();
                        @unlink($tempPath);
                    }
                }
            }
        }
    } elseif ($formAction === 'resolve_units') {
        $tempName = basename(post('temp_file'));
        $tempPath = IMPORT_DIR . '/' . $tempName;

        if ($tempName === '' || !is_file($tempPath)) {
            $errors[] = 'File sementara sudah tidak ada (mungkin kedaluwarsa). Silakan upload ulang.';
        } else {
            $resolutionMap = [];
            foreach ($_POST['unit_resolution'] ?? [] as $rawText => $choice) {
                $key = strtoupper(trim((string) $rawText));
                if ($choice === '__map__') {
                    $target = trim((string) ($_POST['unit_resolution_target'][$rawText] ?? ''));
                    $resolutionMap[$key] = $target !== '' ? $target : $key;
                } else {
                    $resolutionMap[$key] = $key;
                }
            }

            try {
                $extension = strtolower((string) pathinfo($tempPath, PATHINFO_EXTENSION));
                $preview = build_grouped_preview($pdo, $tempPath, $extension, $tempName, $resolutionMap);
                $preview['resolution_map'] = $resolutionMap;
            } catch (Throwable $e) {
                $errors[] = 'Gagal memproses file: ' . $e->getMessage();
            }
        }
    } elseif ($formAction === 'confirm') {
        $tempName = basename(post('temp_file'));
        $duplicateAction = post('duplicate_action') === 'update' ? 'update' : 'skip';
        $tempPath = IMPORT_DIR . '/' . $tempName;

        $resolutionMap = [];
        foreach ($_POST['resolved_units'] ?? [] as $rawText => $code) {
            $resolutionMap[strtoupper(trim((string) $rawText))] = trim((string) $code);
        }

        if ($tempName === '' || !is_file($tempPath)) {
            $errors[] = 'File sementara sudah tidak ada (mungkin sudah diproses atau kedaluwarsa). Silakan upload ulang.';
        } else {
            try {
                // Register any brand-new units chosen in the resolve-units step now,
                // at the point we're actually committing data.
                foreach ($resolutionMap as $code) {
                    find_or_create_unit($pdo, $code);
                }

                $extension = strtolower((string) pathinfo($tempPath, PATHINFO_EXTENSION));
                $data = read_import_spreadsheet($tempPath, $extension);
                $resolvedColumns = resolve_import_columns($data['headers']);

                $mappedRows = [];
                foreach ($data['rows'] as $rawRow) {
                    $mapped = map_import_row($rawRow, $resolvedColumns);
                    $mappedRows[] = ['mapped' => $mapped, 'errors' => validate_import_row($mapped)];
                }
                $mappedRows = apply_unit_resolution($pdo, $mappedRows, $resolutionMap);
                $groups = group_import_rows($mappedRows);

                $groupResults = [];
                $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
                $totalUnitsSaved = 0;
                $batchId = bin2hex(random_bytes(16));

                foreach ($groups as $group) {
                    $result = process_import_group($pdo, $actor['id'], $group, $duplicateAction, $defaultLowStockThreshold, $batchId);
                    $counts[$result['status']]++;
                    $totalUnitsSaved += $result['units_saved'];
                    $groupResults[] = $result;
                }

                log_audit($pdo, 'bulk_import_completed', 'product', null, null, [
                    'file' => $tempName, 'created' => $counts['created'], 'updated' => $counts['updated'],
                    'skipped' => $counts['skipped'], 'failed' => $counts['failed'], 'units_saved' => $totalUnitsSaved,
                ], null, $batchId);

                @unlink($tempPath);

                $results = ['counts' => $counts, 'units_saved' => $totalUnitsSaved, 'groups' => $groupResults];
            } catch (Throwable $e) {
                $errors[] = 'Gagal memproses file: ' . $e->getMessage();
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($results): ?>

<div class="card">
  <strong>Hasil Import</strong>
  <table style="margin-top:10px">
    <tbody>
      <tr><td>Produk baru dibuat</td><td class="text-right"><strong><?= $results['counts']['created'] ?></strong></td></tr>
      <tr><td>Produk diperbarui</td><td class="text-right"><strong><?= $results['counts']['updated'] ?></strong></td></tr>
      <tr><td>Dilewati</td><td class="text-right"><?= $results['counts']['skipped'] ?></td></tr>
      <tr><td>Gagal</td><td class="text-right"><?= $results['counts']['failed'] ?></td></tr>
      <tr><td>Total satuan tambahan tersimpan</td><td class="text-right"><?= $results['units_saved'] ?></td></tr>
    </tbody>
  </table>
</div>

<?php
$issueGroups = array_filter($results['groups'], static fn ($r) => $r['status'] !== 'created' && $r['status'] !== 'updated');
$okGroups = array_filter($results['groups'], static fn ($r) => $r['status'] === 'created' || $r['status'] === 'updated');
?>

<?php if ($issueGroups): ?>
<div class="card">
  <strong>Kode yang Dilewati / Gagal</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Kode Item</th><th>Nama</th><th>Status</th><th>Keterangan</th></tr></thead>
    <tbody>
    <?php foreach ($issueGroups as $r): ?>
      <tr>
        <td><?= e($r['code']) ?></td>
        <td><?= e($r['name']) ?></td>
        <td><span class="badge badge-inactive"><?= $r['status'] === 'skipped' ? 'Dilewati' : 'Gagal' ?></span></td>
        <td class="text-muted"><?= e($r['message']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($okGroups): ?>
<div class="card">
  <strong>Berhasil Diproses (<?= count($okGroups) ?> produk)</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Kode Item</th><th>Nama</th><th>Status</th><th>Keterangan</th></tr></thead>
    <tbody>
    <?php foreach ($okGroups as $r): ?>
      <tr>
        <td><?= e($r['code']) ?></td>
        <td><?= e($r['name']) ?></td>
        <td><span class="badge badge-active"><?= $r['status'] === 'created' ? 'Baru' : 'Diperbarui' ?></span></td>
        <td class="text-muted"><?= e($r['message']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="btn-row">
  <a class="btn" href="<?= APP_BASE_PATH ?>/products/index.php">Ke Daftar Produk</a>
  <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/products/import.php">Import File Lain</a>
</div>

<?php elseif ($unresolvedStep): ?>

<div class="flash flash-warning"><?= count($unresolvedStep['units']) ?> nilai "Satuan" di file tidak dikenali sistem. Tentukan dulu mau diapakan sebelum lanjut ke preview.</div>

<form method="post" novalidate data-loading-message="Memproses satuan...">
  <?= csrf_field() ?>
  <input type="hidden" name="form_action" value="resolve_units">
  <input type="hidden" name="temp_file" value="<?= e($unresolvedStep['temp_name']) ?>">

  <?php foreach ($unresolvedStep['units'] as $rawText): ?>
    <div class="card" style="max-width:640px">
      <strong>"<?= e($rawText) ?>"</strong> — tidak ada di daftar satuan standar.
      <div class="form-group" style="margin-top:8px">
        <label class="checkbox-inline" style="display:block">
          <input type="radio" name="unit_resolution[<?= e($rawText) ?>]" value="__new__" checked>
          Tambahkan sebagai satuan baru: "<?= e(strtoupper($rawText)) ?>"
        </label>
        <label class="checkbox-inline" style="display:block">
          <input type="radio" name="unit_resolution[<?= e($rawText) ?>]" value="__map__">
          Petakan ke satuan yang sudah ada:
        </label>
        <select name="unit_resolution_target[<?= e($rawText) ?>]" style="max-width:320px;margin-left:24px">
          <?php foreach ($unitsList as $u): ?>
            <option value="<?= e($u['code']) ?>"><?= e($u['code']) ?><?= $u['description'] ? ' — ' . e($u['description']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="btn-row">
    <button type="submit" class="btn">Lanjut ke Preview</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/products/import.php">Batal</a>
  </div>
</form>

<?php elseif ($preview): ?>

<?php if ($preview['unknown_headers']): ?>
  <div class="flash flash-warning">Kolom berikut ada di file tapi tidak dikenali sistem, diabaikan: <?= e(implode(', ', $preview['unknown_headers'])) ?></div>
<?php endif; ?>
<?php if ($preview['has_category_column']): ?>
  <div class="flash flash-warning">Kolom "Kategori" ditemukan tapi diabaikan — PARTAMBUS belum punya fitur kategori produk.</div>
<?php endif; ?>
<?php if ($preview['has_wholesale_column']): ?>
  <div class="flash flash-warning">Kolom "Harga Jual 2 (grosir)" ditemukan tapi diabaikan (format lama) — pakai baris satuan tambahan dengan kolom Konversi untuk harga per satuan grosir.</div>
<?php endif; ?>

<div class="card-grid">
  <div class="card">
    <div class="text-muted">Total Baris</div>
    <div style="font-size:22px;font-weight:700"><?= $preview['total_rows'] ?></div>
  </div>
  <div class="card">
    <div class="text-muted">Jumlah Produk (grup Kode Item)</div>
    <div style="font-size:22px;font-weight:700"><?= $preview['group_count'] ?></div>
  </div>
  <div class="card">
    <div class="text-muted">Siap Diimport (baru)</div>
    <div style="font-size:22px;font-weight:700"><?= $preview['product_count'] ?></div>
    <button type="button" class="btn btn-small btn-secondary" style="margin-top:8px" data-preview-filter="ready">Lihat Detail</button>
  </div>
  <div class="card">
    <div class="text-muted">Duplikat (sudah ada)</div>
    <div style="font-size:22px;font-weight:700"><?= $preview['duplicate_count'] ?></div>
    <button type="button" class="btn btn-small btn-secondary" style="margin-top:8px" data-preview-filter="duplicate">Lihat Detail</button>
  </div>
  <div class="card">
    <div class="text-muted">Gagal (tidak bisa diproses)</div>
    <div style="font-size:22px;font-weight:700"><?= $preview['failed_count'] ?></div>
    <button type="button" class="btn btn-small btn-secondary" style="margin-top:8px" data-preview-filter="failed">Lihat Detail</button>
  </div>
</div>

<div class="card" id="import-preview-guard">
  <div class="preview-filter-bar">
    <strong>Preview per Produk</strong>
    <span class="preview-filter-count" data-preview-count></span>
    <button type="button" class="btn btn-small btn-secondary" data-preview-filter="all">Tampilkan Semua</button>
  </div>
  <?php foreach ($preview['groups'] as $group): ?>
    <?php if ($group['group_errors']): ?>
      <?php $status = 'failed'; ?>
    <?php elseif (!empty($group['existing'])): ?>
      <?php $status = 'duplicate'; ?>
    <?php else: ?>
      <?php $status = 'ready'; ?>
    <?php endif; ?>
    <div class="card" style="background:#fafafa;margin-bottom:10px" data-preview-status="<?= $status ?>">
      <?php if ($group['group_errors']): ?>
        <strong>Kode: <?= e($group['code']) ?></strong> — <span class="badge badge-inactive">Gagal</span>
        <p class="text-muted" style="margin-bottom:0"><?= e(implode('; ', $group['group_errors'])) ?></p>
      <?php else: ?>
        <?php $baseRow = $group['rows'][$group['base_index']]['mapped']; ?>
        <strong><?= e($baseRow['name']) ?></strong> (kode: <?= e($group['code']) ?>)
        <?php if (!empty($group['existing'])): ?>
          <span class="badge badge-low-stock">Duplikat</span>
        <?php else: ?>
          <span class="badge badge-active">Baru</span>
        <?php endif; ?>
        <ul style="margin:6px 0 0">
          <li>Satuan dasar: <?= e($baseRow['unit']) ?><?= $baseRow['price'] !== '' ? ', harga jual ' . rupiah($baseRow['price']) : ' (tanpa harga jual)' ?></li>
          <?php foreach ($group['rows'] as $idx => $entry): ?>
            <?php if ($idx === $group['base_index']) continue; ?>
            <?php $row = $entry['mapped']; ?>
            <li>
              <?php if ($entry['errors']): ?>
                <span class="text-danger">Satuan "<?= e($row['unit']) ?>" dilewati: <?= e(implode(', ', $entry['errors'])) ?></span>
              <?php else: ?>
                Satuan tambahan: <?= e($row['unit']) ?> (isi <?= e($row['conversion']) ?> <?= e($baseRow['unit']) ?>)<?= $row['price'] !== '' ? ', harga jual ' . rupiah($row['price']) : '' ?>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" style="max-width:560px">
  <form method="post" novalidate data-preview-guard-scope data-loading-message="Memproses import...">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="confirm">
    <input type="hidden" name="temp_file" value="<?= e($preview['temp_name']) ?>">
    <?php foreach (($preview['resolution_map'] ?? []) as $rawText => $resolvedCode): ?>
      <input type="hidden" name="resolved_units[<?= e($rawText) ?>]" value="<?= e($resolvedCode) ?>">
    <?php endforeach; ?>

    <div class="form-group">
      <label>Untuk produk duplikat (kode/barcode sudah ada di database)</label>
      <label class="checkbox-inline" style="display:block"><input type="radio" name="duplicate_action" value="skip" checked> Lewati (jangan ubah data yang sudah ada)</label>
      <label class="checkbox-inline" style="display:block"><input type="radio" name="duplicate_action" value="update"> Perbarui data yang sudah ada (nama, satuan dasar jika belum ada riwayat stok, harga, dan satuan tambahan)</label>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn" data-confirm="Proses import <?= $preview['group_count'] ?> produk ke database?">Proses Import</button>
      <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/products/import.php" data-preview-guard-bypass>Batal</a>
    </div>
  </form>
</div>

<div class="modal-overlay hidden" id="preview-exit-modal">
  <div class="modal-box">
    <p>Kamu masih dalam proses preview import. Kalau keluar sekarang, data preview ini akan hilang dan proses import dibatalkan. Yakin mau keluar?</p>
    <div class="btn-row">
      <button type="button" class="btn btn-secondary" data-modal-cancel>Batal</button>
      <button type="button" class="btn btn-danger" data-modal-confirm>Ya, Keluar</button>
    </div>
  </div>
</div>

<?php else: ?>

<div class="card" style="max-width:680px">
  <p>Upload file Excel (.xlsx) atau CSV berisi daftar produk. <strong>Boleh beberapa baris per produk</strong> (satu produk = satu satuan dasar + satuan tambahan opsional), dikelompokkan berdasarkan "Kode Item" yang sama.</p>
  <table style="margin-bottom:16px">
    <thead><tr><th>Header di File</th><th>Jadi Apa</th></tr></thead>
    <tbody>
      <tr><td>Kode Item</td><td>Kunci pengelompokan baris — <strong>wajib</strong>, sama untuk semua satuan 1 produk</td></tr>
      <tr><td>Barcode</td><td>Barcode produk — opsional, diambil dari baris satuan dasar saja</td></tr>
      <tr><td>Nama Produk</td><td>Nama produk — <strong>wajib</strong> diisi pada baris satuan dasar</td></tr>
      <tr><td>Satuan</td><td>Nama satuan — <strong>wajib</strong>. Dicocokkan ke daftar satuan standar; kalau tidak dikenali, akan ditanya dulu sebelum lanjut</td></tr>
      <tr><td>Konversi</td><td><strong>wajib</strong>. Isi <code>1</code> untuk satuan dasar produk, atau jumlah satuan dasar per satuan ini (mis. <code>10</code> untuk PACK isi 10 PCS)</td></tr>
      <tr><td>Harga Pokok (Beli)</td><td>Biaya modal (opsional) — <strong>cuma dipakai dari baris Konversi=1</strong>, langsung mengisi harga modal produk</td></tr>
      <tr><td>Harga Jual</td><td>Harga jual per satuan — diisi untuk tiap baris/satuan sendiri-sendiri, boleh beda-beda</td></tr>
    </tbody>
  </table>
  <p class="form-hint">Setiap kode item wajib punya tepat 1 baris dengan Konversi=1 (satuan dasarnya). Stok tidak diambil dari file — semua produk baru dimulai dengan stok 0.</p>

  <form method="post" enctype="multipart/form-data" novalidate data-loading-message="Membaca file...">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="upload">
    <div class="form-group">
      <label for="import_file">File (.xlsx atau .csv)</label>
      <input type="file" id="import_file" name="import_file" accept=".xlsx,.xls,.csv" required>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn">Baca File &amp; Tampilkan Preview</button>
      <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/products/index.php">Batal</a>
    </div>
  </form>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
