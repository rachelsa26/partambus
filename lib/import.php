<?php
declare(strict_types=1);

const IMPORT_DIR = __DIR__ . '/../imports';

/**
 * Column header -> internal field aliases. Matched case-insensitively, trimmed.
 * "category" and "wholesale_price" are legacy single-row-format columns kept
 * recognized (so old files don't throw "unknown column" noise) but never
 * written anywhere — see products/import.php for why.
 */
const IMPORT_COLUMN_ALIASES = [
    'code' => ['kode item', 'kode item (lama)', 'kode'],
    'barcode' => ['barcode'],
    'name' => ['nama produk', 'nama'],
    'category' => ['kategori'],
    'unit' => ['satuan'],
    'conversion' => ['konversi', 'faktor konversi'],
    'cost' => ['harga pokok (beli)', 'harga pokok', 'harga beli'],
    'price' => ['harga jual'],
    'wholesale_price' => ['harga jual 2 (grosir)', 'harga jual 2', 'harga grosir'],
];

/**
 * Reads a .xlsx or .csv file into a plain header row + list of associative
 * data rows (keyed by the literal header text found in the file). Fully
 * blank rows are dropped.
 *
 * @return array{headers: string[], rows: array<int, array<string,string>>}
 */
function read_import_spreadsheet(string $filePath, string $extension): array
{
    $extension = strtolower($extension);
    $numericGrid = null;

    if ($extension === 'csv') {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Tidak bisa membuka file.');
        }
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $rawRows = [];
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $rawRows[] = $row;
        }
        fclose($handle);
    } elseif (in_array($extension, ['xlsx', 'xls'], true)) {
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath)->getActiveSheet();
        // Two aligned reads of the same cells:
        //  - $rawRows: display-formatted strings (formatData=true). Kept as the
        //    default so identifier columns like "Kode Item" preserve a
        //    leading-zero display mask (e.g. a cell styled "00000" holding the
        //    number 1 reads back as "00001").
        //  - $numericGrid: raw calculated values (formatData=false), used below
        //    to override the Konversi/Harga Pokok/Harga Jual columns only —
        //    because a cell styled with Excel's "Comma Style"/accounting
        //    number format comes back from the formatted read as a literal
        //    string like "  15,000 " (padding + thousands separator baked in),
        //    which then fails numeric validation on every row that has it.
        $rawRows = $sheet->toArray(null, true, true, false);
        $numericGrid = $sheet->toArray(null, true, false, false);
    } else {
        throw new RuntimeException('Format file tidak didukung. Gunakan .xlsx atau .csv.');
    }

    if (!$rawRows) {
        throw new RuntimeException('File kosong.');
    }

    $headerRow = array_map(static fn ($h) => trim((string) $h), array_shift($rawRows));

    $numericColumnIndexes = [];
    if ($numericGrid !== null) {
        array_shift($numericGrid); // drop header row to stay row-aligned with $rawRows
        $resolvedForNumeric = resolve_import_columns($headerRow);
        foreach (['conversion', 'cost', 'price'] as $field) {
            if (!isset($resolvedForNumeric[$field])) {
                continue;
            }
            $idx = array_search($resolvedForNumeric[$field], $headerRow, true);
            if ($idx !== false) {
                $numericColumnIndexes[$idx] = true;
            }
        }
    }

    $dataRows = [];
    foreach ($rawRows as $rowNum => $row) {
        $hasValue = false;
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                $hasValue = true;
                break;
            }
        }
        if (!$hasValue) {
            continue;
        }

        $assoc = [];
        foreach ($headerRow as $i => $h) {
            if (isset($numericColumnIndexes[$i]) && isset($numericGrid[$rowNum][$i]) && $numericGrid[$rowNum][$i] !== null) {
                $assoc[$h] = trim((string) $numericGrid[$rowNum][$i]);
            } else {
                $assoc[$h] = trim((string) ($row[$i] ?? ''));
            }
        }
        $dataRows[] = $assoc;
    }

    return ['headers' => $headerRow, 'rows' => $dataRows];
}

/** @return array<string,string> internal field => literal header text found in the file (missing fields absent) */
function resolve_import_columns(array $headerRow): array
{
    $normalized = [];
    foreach ($headerRow as $h) {
        $normalized[strtolower(trim($h))] = $h;
    }

    $resolved = [];
    foreach (IMPORT_COLUMN_ALIASES as $field => $aliases) {
        foreach ($aliases as $alias) {
            if (isset($normalized[$alias])) {
                $resolved[$field] = $normalized[$alias];
                break;
            }
        }
    }
    return $resolved;
}

/** @return array{code:string, barcode:string, name:string, unit:string, conversion:string, cost:string, price:string} */
function map_import_row(array $row, array $resolvedColumns): array
{
    $get = static fn (string $field): string => isset($resolvedColumns[$field]) ? ($row[$resolvedColumns[$field]] ?? '') : '';

    return [
        'code' => $get('code'),
        'barcode' => $get('barcode'),
        'name' => $get('name'),
        'unit' => $get('unit'),
        'conversion' => $get('conversion'),
        'cost' => $get('cost'),
        'price' => $get('price'),
    ];
}

/**
 * Lenient parse of the "Konversi" column. Real spreadsheet exports commonly
 * produce values that LOOK like a plain "1" but aren't the literal string
 * "1": PhpSpreadsheet may hand back a formula-derived float with tiny
 * floating-point error (e.g. 0.9999999999998), or a locale export may use a
 * comma decimal ("1,0"), or the cell may carry a stray non-breaking space
 * from a copy-paste. ctype_digit()/(int) cast alone reject all of these even
 * though the value is unambiguously "1" to a human. Returns null only when
 * the value genuinely isn't a positive whole number.
 */
function parse_import_conversion(string $raw): ?int
{
    $clean = trim(str_replace("\xC2\xA0", ' ', $raw)); // strip UTF-8 non-breaking spaces too
    if ($clean === '') {
        return null;
    }

    // Accept "1,0" (comma decimal) alongside "1.0" (dot decimal) — Konversi
    // values are always small, so a comma here is never a thousands separator.
    $normalized = preg_replace('/^(-?\d+),(\d+)$/', '$1.$2', $clean);
    if (!is_numeric($normalized)) {
        return null;
    }

    $float = (float) $normalized;
    $rounded = round($float);
    if ($rounded < 1 || abs($float - $rounded) > 0.0001) {
        return null;
    }

    return (int) $rounded;
}

/**
 * Structural, per-row validation only (doesn't know about grouping yet).
 * Name is intentionally NOT required here — it's only required on whichever
 * row turns out to be the base unit (Konversi=1) for its group, checked in
 * group_import_rows().
 *
 * @return string[] validation error messages; empty array = structurally valid
 */
function validate_import_row(array $mapped): array
{
    $errors = [];

    if ($mapped['code'] === '') {
        $errors[] = 'Kode Item kosong';
    }
    if ($mapped['unit'] === '') {
        $errors[] = 'Satuan kosong';
    }
    if (parse_import_conversion($mapped['conversion']) === null) {
        $errors[] = 'Konversi harus bilangan bulat >= 1';
    }
    if ($mapped['cost'] !== '' && (!is_numeric($mapped['cost']) || (float) $mapped['cost'] < 0)) {
        $errors[] = 'Harga Pokok bukan angka yang valid';
    }
    if ($mapped['price'] !== '' && (!is_numeric($mapped['price']) || (float) $mapped['price'] < 0)) {
        $errors[] = 'Harga Jual bukan angka yang valid';
    }

    return $errors;
}

/**
 * Groups mapped rows by normalized Kode Item and identifies each group's
 * base-unit row (Konversi = 1). A group with zero or more than one Konversi=1
 * row is marked failed via group_errors and must not be processed.
 *
 * Which row IS the base is decided purely from the Konversi column, decoupled
 * from whether that row has OTHER field errors (e.g. a bad Harga Pokok) — a
 * row with Konversi=1 but a bad price is still "the base row", just an
 * invalid one, and gets reported as such instead of the misleading "no base
 * row found" (which previously fired any time the base row had ANY error).
 *
 * @param array<int, array{mapped: array, errors: string[]}> $mappedRows
 * @return array<string, array{code:string, rows: array, base_index: ?int, group_errors: string[]}>
 */
function group_import_rows(array $mappedRows): array
{
    $groups = [];

    foreach ($mappedRows as $entry) {
        $code = trim($entry['mapped']['code']);
        if ($code === '') {
            continue; // no grouping key; already reported as a row-level error
        }
        $key = normalize_code($code);
        if (!isset($groups[$key])) {
            $groups[$key] = ['code' => $entry['mapped']['code'], 'rows' => [], 'base_index' => null, 'group_errors' => []];
        }
        $groups[$key]['rows'][] = $entry;
    }

    foreach ($groups as $key => &$group) {
        $baseIndices = [];
        foreach ($group['rows'] as $idx => $entry) {
            if (parse_import_conversion($entry['mapped']['conversion']) === 1) {
                $baseIndices[] = $idx;
            }
        }

        if (count($baseIndices) === 0) {
            $group['group_errors'][] = 'Tidak ada baris satuan dasar (Konversi=1) untuk kode ini';
        } elseif (count($baseIndices) > 1) {
            $group['group_errors'][] = 'Ada ' . count($baseIndices) . ' baris dengan Konversi=1 untuk kode ini — harus tepat 1';
        } else {
            $idx = $baseIndices[0];
            $entry = $group['rows'][$idx];
            if ($entry['errors']) {
                $group['group_errors'][] = 'Baris satuan dasar (Konversi=1) tidak valid: ' . implode('; ', $entry['errors']);
            } elseif (trim($entry['mapped']['name']) === '') {
                $group['group_errors'][] = 'Nama Produk kosong pada baris satuan dasar';
            } else {
                $group['base_index'] = $idx;
            }
        }
    }
    unset($group);

    return $groups;
}

/** Looks up an existing product by normalized code or barcode, read-only. */
function find_existing_product_for_import(PDO $pdo, string $codeNormalized, ?string $barcode): ?array
{
    $stmt = $pdo->prepare('SELECT id, code, name, base_unit_name FROM products WHERE code_normalized = ?');
    $stmt->execute([$codeNormalized]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    if ($barcode !== null) {
        $stmt = $pdo->prepare('SELECT id, code, name, base_unit_name FROM products WHERE barcode = ?');
        $stmt->execute([$barcode]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    return null;
}

/**
 * Creates or updates one product + all its units from an already-grouped,
 * already-unit-resolved batch. Runs its own transaction; never throws —
 * failures come back as status=failed so one bad group can't affect others.
 *
 * @param array{code:string, rows: array, base_index: ?int, group_errors: string[]} $group
 * @return array{status:string, message:string, code:string, name:string, units_saved:int}
 */
function process_import_group(PDO $pdo, int $actorId, array $group, string $duplicateAction, int $defaultLowStockThreshold, ?string $batchId = null): array
{
    if ($group['group_errors']) {
        return [
            'status' => 'failed', 'message' => implode('; ', $group['group_errors']),
            'code' => $group['code'], 'name' => '', 'units_saved' => 0,
        ];
    }

    $baseRow = $group['rows'][$group['base_index']]['mapped'];
    $codeNormalized = normalize_code($baseRow['code']);
    $barcode = $baseRow['barcode'] !== '' ? $baseRow['barcode'] : null;
    $price = $baseRow['price'] !== '' && is_numeric($baseRow['price']) ? (float) $baseRow['price'] : null;
    $cost = $baseRow['cost'] !== '' && is_numeric($baseRow['cost']) ? (float) $baseRow['cost'] : null;

    $existing = find_existing_product_for_import($pdo, $codeNormalized, $barcode);
    if ($existing && $duplicateAction === 'skip') {
        return [
            'status' => 'skipped', 'message' => 'Kode/barcode sudah ada di database (dilewati sesuai pilihan)',
            'code' => $baseRow['code'], 'name' => $baseRow['name'], 'units_saved' => 0,
        ];
    }

    // Additional-unit rows: skip this group's own structurally-invalid rows
    // and any unit-name repeated within the same group, without failing the
    // whole group over it.
    $additionalRows = [];
    $unitNamesSeen = [strtolower($baseRow['unit'])];
    foreach ($group['rows'] as $idx => $entry) {
        if ($idx === $group['base_index'] || $entry['errors']) {
            continue;
        }
        $mapped = $entry['mapped'];
        $lower = strtolower($mapped['unit']);
        if (in_array($lower, $unitNamesSeen, true)) {
            continue;
        }
        $unitNamesSeen[] = $lower;
        $additionalRows[] = $mapped;
    }

    $pdo->beginTransaction();
    try {
        if ($existing) {
            $productId = (int) $existing['id'];

            $collideCode = $pdo->prepare('SELECT id FROM products WHERE code_normalized = ? AND id != ?');
            $collideCode->execute([$codeNormalized, $productId]);
            if ($collideCode->fetch()) {
                throw new RuntimeException('Kode sudah dipakai produk lain');
            }
            if ($barcode !== null) {
                $collideBarcode = $pdo->prepare('SELECT id FROM products WHERE barcode = ? AND id != ?');
                $collideBarcode->execute([$barcode, $productId]);
                if ($collideBarcode->fetch()) {
                    throw new RuntimeException('Barcode sudah dipakai produk lain');
                }
            }

            $movementCheck = $pdo->prepare('SELECT 1 FROM stock_movements WHERE product_id = ? LIMIT 1');
            $movementCheck->execute([$productId]);
            $hasMovements = (bool) $movementCheck->fetch();
            $baseUnitName = $hasMovements ? $existing['base_unit_name'] : $baseRow['unit'];

            $pdo->prepare(
                'UPDATE products SET code = ?, code_normalized = ?, barcode = ?, name = ?, base_unit_name = ?, updated_by = ? WHERE id = ?'
            )->execute([$baseRow['code'], $codeNormalized, $barcode, $baseRow['name'], $baseUnitName, $actorId, $productId]);

            $baseUnitStmt = $pdo->prepare('SELECT id FROM product_units WHERE product_id = ? AND is_base = 1 LIMIT 1');
            $baseUnitStmt->execute([$productId]);
            $baseUnitId = (int) $baseUnitStmt->fetchColumn();
            if ($baseUnitId && $price !== null) {
                $pdo->prepare('UPDATE product_units SET can_sell = 1, selling_price = ? WHERE id = ?')->execute([$price, $baseUnitId]);
            }

            $resultStatus = 'updated';
        } else {
            $pdo->prepare(
                'INSERT INTO products (code, code_normalized, barcode, name, base_unit_name, low_stock_threshold_base, active, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
            )->execute([$baseRow['code'], $codeNormalized, $barcode, $baseRow['name'], $baseRow['unit'], $defaultLowStockThreshold, $actorId, $actorId]);
            $productId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO product_units (product_id, unit_name, conversion_factor, is_base, can_sell, can_purchase, selling_price, active)
                 VALUES (?, ?, 1, 1, ?, 1, ?, 1)'
            )->execute([$productId, $baseRow['unit'], $price !== null ? 1 : 0, $price]);

            $resultStatus = 'created';
        }

        // Cost/WAC comes ONLY from the base row — product_units has no cost
        // column at all, and per-unit "Harga Pokok" is informational only
        // (confirmed decision: WAC is a single per-product concept).
        if ($cost !== null) {
            $lock = $pdo->prepare('SELECT current_stock_base, current_wac FROM products WHERE id = ? FOR UPDATE');
            $lock->execute([$productId]);
            $locked = $lock->fetch();
            $oldStock = (int) $locked['current_stock_base'];
            $oldWac = $locked['current_wac'] !== null ? (float) $locked['current_wac'] : null;

            record_stock_movement($pdo, $productId, 'initial_stock', 0, $cost, 'import', null, $actorId, 'Import massal produk');

            $newWac = calculate_new_wac($oldWac, $oldStock, 0, $cost);
            $pdo->prepare('UPDATE products SET current_wac = ? WHERE id = ?')->execute([$newWac, $productId]);
        }

        $unitsSaved = 0;
        foreach ($additionalRows as $row) {
            $rowPrice = $row['price'] !== '' && is_numeric($row['price']) ? (float) $row['price'] : null;
            $rowConversion = parse_import_conversion($row['conversion']) ?? 1;

            $existingUnitStmt = $pdo->prepare('SELECT id, conversion_factor FROM product_units WHERE product_id = ? AND unit_name = ?');
            $existingUnitStmt->execute([$productId, $row['unit']]);
            $existingUnit = $existingUnitStmt->fetch();

            if ($existingUnit) {
                $unitUsedCheck = $pdo->prepare(
                    '(SELECT 1 FROM purchase_items pi JOIN purchases pu ON pu.id = pi.purchase_id
                      WHERE pi.unit_id = ? AND pu.status != "cancelled_draft" LIMIT 1)
                     UNION (SELECT 1 FROM sale_items WHERE unit_id = ? LIMIT 1) LIMIT 1'
                );
                $unitUsedCheck->execute([$existingUnit['id'], $existingUnit['id']]);
                $isUsed = (bool) $unitUsedCheck->fetch();

                if ($isUsed && (int) $existingUnit['conversion_factor'] !== $rowConversion) {
                    // BR-008: conversion of a used unit can't change — keep it, just refresh price
                    $pdo->prepare('UPDATE product_units SET can_sell = ?, selling_price = ? WHERE id = ?')
                        ->execute([$rowPrice !== null ? 1 : 0, $rowPrice, $existingUnit['id']]);
                } else {
                    $pdo->prepare('UPDATE product_units SET conversion_factor = ?, can_sell = ?, can_purchase = 1, selling_price = ? WHERE id = ?')
                        ->execute([$rowConversion, $rowPrice !== null ? 1 : 0, $rowPrice, $existingUnit['id']]);
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO product_units (product_id, unit_name, conversion_factor, is_base, can_sell, can_purchase, selling_price, active)
                     VALUES (?, ?, ?, 0, ?, 1, ?, 1)'
                )->execute([$productId, $row['unit'], $rowConversion, $rowPrice !== null ? 1 : 0, $rowPrice]);
            }
            $unitsSaved++;
        }

        log_audit(
            $pdo,
            $resultStatus === 'created' ? 'product_created' : 'product_updated',
            'product',
            $productId,
            null,
            ['source' => 'bulk_import', 'code' => $baseRow['code'], 'name' => $baseRow['name'], 'units_saved' => $unitsSaved],
            null,
            $batchId
        );

        $pdo->commit();

        $note = $price === null ? ' (satuan dasar tanpa harga jual — belum bisa dijual sampai dilengkapi)' : '';
        return [
            'status' => $resultStatus,
            'message' => ($resultStatus === 'created' ? 'Produk baru dibuat' : 'Produk diperbarui') . ", $unitsSaved satuan tambahan tersimpan" . $note,
            'code' => $baseRow['code'],
            'name' => $baseRow['name'],
            'units_saved' => $unitsSaved,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['status' => 'failed', 'message' => $e->getMessage(), 'code' => $baseRow['code'], 'name' => $baseRow['name'], 'units_saved' => 0];
    }
}
