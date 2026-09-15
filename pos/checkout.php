<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_login();
$pageTitle = 'Pembayaran';

// The POS "Bayar" modal (pos/index.php) submits here via fetch with this
// header so it gets a JSON response instead of a full-page redirect, letting
// it show the success/error state in place without leaving the cart page.
// Direct navigation to this URL (no JS, bookmarked, etc.) still gets the
// normal full-page flow below — the payment logic itself is identical either
// way, only the response format branches.
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$_SESSION['cart'] ??= [];

if (!$_SESSION['cart']) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'errors' => ['Keranjang masih kosong.']]);
        exit;
    }
    flash_set('error', 'Keranjang masih kosong.');
    redirect('/pos/index.php');
}

$errors = [];
$paymentMethods = PAYMENT_METHOD_LABELS;

if (is_post()) {
    verify_csrf();
    $method = post('method');
    $cashReceived = post('cash_received');
    $requestToken = post('request_token');

    if (!isset($_SESSION['sale_request_token']) || $_SESSION['sale_request_token'] !== $requestToken) {
        $errors[] = 'Sesi pembayaran sudah kedaluwarsa. Silakan ulangi.';
    }
    if (!array_key_exists($method, $paymentMethods)) {
        $errors[] = 'Pilih metode pembayaran.';
    }

    $activeSession = get_active_cash_session($pdo);
    if ($method === 'cash' && !$activeSession) {
        $errors[] = 'Belum ada sesi kas aktif. Buka sesi kas dulu sebelum menerima pembayaran tunai.';
    }

    $previewLines = get_cart_lines_with_details($pdo, $_SESSION['cart']);
    $previewTotals = cart_totals($previewLines);

    // Explicit safety net: reject upfront (with a clear per-product message)
    // if any cart line isn't a fully valid, in-stock, priced item — on top
    // of the FOR UPDATE lock-and-recheck below, which stays as the final
    // guard against a race with another change between this check and the
    // actual commit. Cart lines can no longer be added without a concrete
    // unit_id (see get_cart_lines_with_details), so this should never
    // actually trigger for that reason — it's here in case a product/unit
    // gets deactivated or its stock drops out from under an item already
    // sitting in the cart.
    foreach ($previewLines as $previewLine) {
        if (!$previewLine['valid']) {
            $errors[] = 'Produk "' . ($previewLine['name'] ?? $previewLine['key']) . '" sudah tidak valid atau tidak bisa dijual. Hapus dari keranjang dan coba lagi.';
        } elseif (!$previewLine['stock_ok']) {
            $errors[] = 'Stok "' . $previewLine['name'] . '" tidak mencukupi (sisa ' . (int) $previewLine['current_stock_base'] . '). Perbaiki jumlahnya di keranjang.';
        }
    }

    if ($method === 'cash') {
        if (!is_numeric($cashReceived) || (float) $cashReceived < $previewTotals['total']) {
            $errors[] = 'Uang diterima kurang dari total belanja.';
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $lockedLines = [];
            $subtotal = 0.0;
            $discountTotal = 0.0;

            foreach ($_SESSION['cart'] as $key => $entry) {
                $lock = $pdo->prepare(
                    'SELECT pu.id AS unit_id, pu.unit_name, pu.conversion_factor, pu.selling_price, pu.can_sell, pu.active AS unit_active,
                            p.id AS product_id, p.code, p.name, p.active AS product_active, p.current_stock_base, p.current_wac
                     FROM product_units pu JOIN products p ON p.id = pu.product_id
                     WHERE pu.id = ? AND p.id = ? FOR UPDATE'
                );
                $lock->execute([$entry['unit_id'], $entry['product_id']]);
                $row = $lock->fetch();

                if (!$row || (int) $row['product_active'] !== 1 || (int) $row['unit_active'] !== 1 || (int) $row['can_sell'] !== 1) {
                    throw new RuntimeException('Produk "' . ($row['name'] ?? $key) . '" sudah tidak tersedia untuk dijual. Hapus dari keranjang dan coba lagi.');
                }

                $qtyUnit = (int) $entry['qty'];
                $conversion = (int) $row['conversion_factor'];
                $qtyBase = $qtyUnit * $conversion;
                $unitPrice = (float) $row['selling_price'];
                $lineSubtotal = $unitPrice * $qtyUnit;
                $discount = (float) $entry['discount_amount'];
                $lineTotal = $lineSubtotal - $discount;
                $wac = $row['current_wac'] !== null ? (float) $row['current_wac'] : null;
                $cogsTotal = $wac !== null ? round($wac * $qtyBase, 2) : null;

                if ((int) $row['current_stock_base'] < $qtyBase) {
                    throw new RuntimeException('Stok "' . $row['name'] . '" tidak mencukupi. Sisa: ' . $row['current_stock_base'] . '.');
                }
                if (!is_owner() && $cogsTotal !== null && $lineTotal < $cogsTotal) {
                    throw new RuntimeException('Harga "' . $row['name'] . '" setelah diskon di bawah batas yang diizinkan. Perlu approval Owner.');
                }

                $lockedLines[] = [
                    'product_id' => (int) $row['product_id'], 'product_code' => $row['code'], 'product_name' => $row['name'],
                    'unit_id' => (int) $row['unit_id'], 'unit_name' => $row['unit_name'], 'conversion_factor' => $conversion,
                    'qty_unit' => $qtyUnit, 'qty_base' => $qtyBase, 'unit_price' => $unitPrice,
                    'discount_amount' => $discount, 'line_total' => $lineTotal,
                    'unit_cost_snapshot' => $wac, 'cogs_total' => $cogsTotal,
                ];
                $subtotal += $lineSubtotal;
                $discountTotal += $discount;
            }

            $total = $subtotal - $discountTotal;
            $prefix = get_setting($pdo, 'sale_number_prefix', 'SL');
            $saleNumber = generate_daily_number($pdo, 'sale_number_counters', $prefix);

            $sessionIdForSale = $method === 'cash' ? (int) $activeSession['id'] : null;

            $insertSale = $pdo->prepare(
                'INSERT INTO sales (sale_number, request_token, status, cashier_id, cash_session_id, subtotal, discount_total, total)
                 VALUES (?, ?, "completed", ?, ?, ?, ?, ?)'
            );
            try {
                $insertSale->execute([$saleNumber, $requestToken, $actor['id'], $sessionIdForSale, $subtotal, $discountTotal, $total]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $pdo->rollBack();
                    $existing = $pdo->prepare('SELECT id FROM sales WHERE request_token = ?');
                    $existing->execute([$requestToken]);
                    $existingId = $existing->fetchColumn();
                    if ($existingId) {
                        $_SESSION['cart'] = [];
                        unset($_SESSION['sale_request_token']);
                        if ($isAjax) {
                            $existingStmt = $pdo->prepare(
                                'SELECT s.sale_number, s.total, p.method, p.change_amount FROM sales s
                                 LEFT JOIN payments p ON p.sale_id = s.id WHERE s.id = ?'
                            );
                            $existingStmt->execute([$existingId]);
                            $existingSale = $existingStmt->fetch();
                            header('Content-Type: application/json; charset=UTF-8');
                            echo json_encode([
                                'success' => true,
                                'sale_id' => (int) $existingId,
                                'sale_number' => $existingSale['sale_number'] ?? '',
                                'method' => $existingSale['method'] ?? null,
                                'total' => isset($existingSale['total']) ? (float) $existingSale['total'] : null,
                                'change_amount' => isset($existingSale['change_amount']) ? (float) $existingSale['change_amount'] : null,
                            ]);
                            exit;
                        }
                        redirect('/sales/view.php?id=' . $existingId);
                    }
                }
                throw $e;
            }
            $saleId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO sale_items
                    (sale_id, product_id, product_code_snapshot, product_name_snapshot, unit_id, unit_name_snapshot,
                     conversion_factor_snapshot, qty_unit, qty_base, unit_price, discount_amount, line_total, unit_cost_snapshot, cogs_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($lockedLines as $line) {
                $itemStmt->execute([
                    $saleId, $line['product_id'], $line['product_code'], $line['product_name'],
                    $line['unit_id'], $line['unit_name'], $line['conversion_factor'],
                    $line['qty_unit'], $line['qty_base'], $line['unit_price'],
                    $line['discount_amount'], $line['line_total'], $line['unit_cost_snapshot'], $line['cogs_total'],
                ]);
                record_stock_movement(
                    $pdo, $line['product_id'], 'sale', -$line['qty_base'], $line['unit_cost_snapshot'], 'sale', $saleId, $actor['id'], null
                );
            }

            $changeAmount = $method === 'cash' ? round((float) $cashReceived - $total, 2) : null;
            $payStmt = $pdo->prepare(
                'INSERT INTO payments (sale_id, method, amount, cash_received, change_amount) VALUES (?, ?, ?, ?, ?)'
            );
            $payStmt->execute([$saleId, $method, $total, $method === 'cash' ? (float) $cashReceived : null, $changeAmount]);

            if ($method === 'cash') {
                record_cash_movement($pdo, $sessionIdForSale, 'cash_sale', $total, 'sale', $saleId, $actor['id'], null);
            }

            $pdo->commit();

            $_SESSION['cart'] = [];
            unset($_SESSION['sale_request_token']);

            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'success' => true,
                    'sale_id' => $saleId,
                    'sale_number' => $saleNumber,
                    'method' => $method,
                    'total' => $total,
                    'change_amount' => $changeAmount,
                ]);
                exit;
            }

            flash_set('success', 'Transaksi ' . $saleNumber . ' berhasil disimpan.');
            redirect('/sales/view.php?id=' . $saleId . '&just_completed=1');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }

    if ($isAjax && $errors) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
}

if (empty($_SESSION['sale_request_token'])) {
    $_SESSION['sale_request_token'] = bin2hex(random_bytes(24));
}

$lines = get_cart_lines_with_details($pdo, $_SESSION['cart']);
$totals = cart_totals($lines);
$hasInvalid = false;
foreach ($lines as $l) {
    if (!$l['valid'] || !$l['stock_ok']) {
        $hasInvalid = true;
    }
}
$cashSessionActive = get_active_cash_session($pdo) !== null;
$quickCashAmounts = pos_quick_cash_amounts($totals['total']);

require __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card">
  <table>
    <thead><tr><th>Produk</th><th>Unit</th><th class="text-right">Qty</th><th class="text-right">Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $line): ?>
      <tr>
        <td><?= e((string) $line['name']) ?><?php if (!$line['valid'] || !$line['stock_ok']): ?> <span class="text-danger">(bermasalah)</span><?php endif; ?></td>
        <td><?= e((string) $line['unit_name']) ?></td>
        <td class="text-right"><?= (int) $line['qty_unit'] ?></td>
        <td class="text-right"><?= rupiah($line['line_total']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="3" class="text-right"><strong>Total</strong></td><td class="text-right"><strong style="font-size:16px"><?= rupiah($totals['total']) ?></strong></td></tr>
    </tfoot>
  </table>
</div>

<div class="card pos-total-card">
  <div class="pos-total-label">Total Belanja</div>
  <div class="pos-total-amount"><?= rupiah($totals['total']) ?></div>
</div>

<?php if ($hasInvalid): ?>
  <div class="flash flash-error">Ada item di keranjang yang sudah tidak valid atau stoknya kurang. <a href="<?= APP_BASE_PATH ?>/pos/index.php">Kembali ke keranjang</a> untuk memperbaiki.</div>
<?php else: ?>
<div class="card" style="max-width:520px">
  <form method="post" novalidate data-loading-message="Memproses transaksi..." id="checkout-form">
    <?= csrf_field() ?>
    <input type="hidden" name="request_token" value="<?= e($_SESSION['sale_request_token']) ?>">
    <div class="form-group">
      <label>Metode Pembayaran</label>
      <?php if (!$cashSessionActive): ?>
        <p class="flash flash-warning" style="margin-bottom:8px">Tunai tidak bisa dipilih: belum ada sesi kas aktif. <a href="<?= APP_BASE_PATH ?>/cash/open.php">Buka sesi kas</a> dulu, atau pakai metode lain.</p>
      <?php endif; ?>
      <?php $firstChecked = $cashSessionActive ? 'cash' : 'qris'; ?>
      <?php foreach ($paymentMethods as $key => $label): ?>
        <label class="checkbox-inline" style="display:block">
          <input type="radio" name="method" value="<?= e($key) ?>" data-method-radio required
            <?= ($key === 'cash' && !$cashSessionActive) ? 'disabled' : '' ?>
            <?= $key === $firstChecked ? 'checked' : '' ?>>
          <?= e($label) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="form-group" id="cash-field">
      <label for="cash_received">Uang Diterima</label>
      <div class="cash-quick-buttons">
        <?php foreach ($quickCashAmounts as $i => $amount): ?>
          <button type="button" class="cash-quick-btn" data-cash-amount="<?= $amount ?>"><?= $i === 0 ? 'Uang Pas' : '' ?> <?= rupiah($amount) ?></button>
        <?php endforeach; ?>
      </div>
      <input type="number" id="cash_received" name="cash_received" min="0" step="1" class="pos-cash-input" data-pos-total="<?= (int) ceil($totals['total']) ?>">
      <p class="form-hint">Total belanja: <?= rupiah($totals['total']) ?></p>
      <div id="change-preview" class="change-preview hidden"></div>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn btn-xlarge">Selesaikan Transaksi</button>
      <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/pos/index.php">Kembali ke Keranjang</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
