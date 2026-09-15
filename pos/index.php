<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_login();
$pageTitle = 'Kasir (POS)';

$_SESSION['cart'] ??= [];

$errors = [];

if (is_post()) {
    verify_csrf();
    $posAction = post('pos_action');
    // Every cart-row form carries the current search query along (see the
    // hidden "q" input on each row) purely so it survives that row's own
    // full-page redirect — changing a line's qty/unit/discount, or removing
    // it, must never blank out "Produk Ditemukan" underneath.
    $cartReturnQuery = post('q');
    $cartRedirectUrl = '/pos/index.php' . ($cartReturnQuery !== '' ? '?q=' . urlencode($cartReturnQuery) : '');

    if ($posAction === 'add_to_cart') {
        $productId = (int) post('product_id');
        $unitId = (int) post('unit_id');
        $qty = post('qty', '1');
        // Carried along so the redirect lands back on the same search results
        // instead of resetting to an empty search box — a cashier adding
        // several matches from one search (e.g. "kecap") shouldn't have to
        // retype the query after every single item.
        $returnQuery = post('q');
        // Set by the barcode-scan auto-add path instead — that one WANTS
        // the search box cleared (ready for the next scan), plus a one-shot
        // "Ditambahkan: ..." toast after the redirect.
        $wasScanned = post('scanned') === '1';

        $check = $pdo->prepare(
            'SELECT pu.id, p.name FROM product_units pu JOIN products p ON p.id = pu.product_id
             WHERE pu.id = ? AND pu.product_id = ? AND pu.active = 1 AND pu.can_sell = 1 AND p.active = 1'
        );
        $check->execute([$unitId, $productId]);
        $checkRow = $check->fetch();

        if (!$checkRow) {
            $errors[] = 'Produk/unit tidak valid atau tidak bisa dijual.';
        } elseif (!ctype_digit($qty) || (int) $qty <= 0) {
            $errors[] = 'Jumlah harus bilangan bulat positif.';
        } else {
            $key = $productId . '_' . $unitId;
            if (isset($_SESSION['cart'][$key])) {
                $_SESSION['cart'][$key]['qty'] += (int) $qty;
            } else {
                $_SESSION['cart'][$key] = ['product_id' => $productId, 'unit_id' => $unitId, 'qty' => (int) $qty, 'discount_amount' => 0.0];
            }
            unset($_SESSION['sale_request_token']);

            if ($wasScanned) {
                redirect('/pos/index.php?scanned=' . urlencode($checkRow['name']));
            }
            redirect('/pos/index.php' . ($returnQuery !== '' ? '?q=' . urlencode($returnQuery) : ''));
        }
    } elseif ($posAction === 'update_line') {
        $key = post('key');
        $qty = post('qty', '1');
        $discount = post('discount_amount', '0');

        if (!isset($_SESSION['cart'][$key])) {
            $errors[] = 'Item keranjang tidak ditemukan.';
        } elseif (!ctype_digit($qty) || (int) $qty <= 0) {
            $errors[] = 'Jumlah harus bilangan bulat positif.';
        } elseif (!is_numeric($discount) || (float) $discount < 0) {
            $errors[] = 'Diskon harus angka >= 0.';
        } else {
            $entry = $_SESSION['cart'][$key];
            $entry['qty'] = (int) $qty;
            $entry['discount_amount'] = 0.0;
            $lines = get_cart_lines_with_details($pdo, [$key => $entry]);
            $line = $lines[0];

            if (!$line['valid']) {
                $errors[] = 'Produk/unit ini sudah tidak tersedia untuk dijual. Hapus dari keranjang.';
            } else {
                $discountValue = (float) $discount;
                $maxDiscountPercent = is_owner() ? null : (float) get_setting($pdo, 'cashier_max_discount_percent', '0');

                if ($maxDiscountPercent !== null) {
                    $capAmount = $line['line_subtotal'] * ($maxDiscountPercent / 100);
                    if ($discountValue > $capAmount + 0.0001) {
                        $errors[] = 'Diskon melebihi batas kasir (' . $maxDiscountPercent . '%). Perlu approval Owner.';
                    }
                }

                if (!$errors) {
                    $netTotal = $line['line_subtotal'] - $discountValue;
                    if (!is_owner() && $line['cogs_total'] !== null && $netTotal < $line['cogs_total']) {
                        $errors[] = 'Harga setelah diskon di bawah batas yang diizinkan untuk kasir. Perlu approval Owner.';
                    }
                }

                if (!$errors) {
                    $_SESSION['cart'][$key]['qty'] = (int) $qty;
                    $_SESSION['cart'][$key]['discount_amount'] = $discountValue;
                    unset($_SESSION['sale_request_token']);

                    if (is_owner() && $line['cogs_total'] !== null && ($line['line_subtotal'] - $discountValue) < $line['cogs_total']) {
                        flash_set('warning', 'Perhatian: harga item "' . $line['name'] . '" setelah diskon berada di bawah harga modal.');
                    }
                    redirect($cartRedirectUrl);
                }
            }
        }
    } elseif ($posAction === 'set_unit') {
        // "Ubah satuan" dropdown on a cart row — the cashier changed their
        // mind after the fact (e.g. added as PCS, buyer actually wants
        // PACK). Re-prices the same line at the new unit's price; qty stays
        // (same count, reinterpreted at the new unit), discount resets to 0
        // since an absolute Rp discount doesn't carry meaning across a price
        // change. This never leaves the cart in an unpriced/incomplete state
        // — add_to_cart always supplies a concrete unit_id up front.
        $oldKey = post('key');
        $unitId = (int) post('unit_id');
        $entry = $_SESSION['cart'][$oldKey] ?? null;

        if (!$entry) {
            $errors[] = 'Item keranjang tidak ditemukan.';
        } elseif ($unitId === (int) $entry['unit_id']) {
            redirect($cartRedirectUrl);
        } else {
            $productId = (int) $entry['product_id'];
            $unitCheck = $pdo->prepare(
                'SELECT id FROM product_units WHERE id = ? AND product_id = ? AND active = 1 AND can_sell = 1'
            );
            $unitCheck->execute([$unitId, $productId]);

            if (!$unitCheck->fetch()) {
                $errors[] = 'Satuan tidak valid.';
            } else {
                unset($_SESSION['cart'][$oldKey]);
                $newKey = $productId . '_' . $unitId;
                if (isset($_SESSION['cart'][$newKey])) {
                    $_SESSION['cart'][$newKey]['qty'] += $entry['qty'];
                } else {
                    $entry['unit_id'] = $unitId;
                    $entry['discount_amount'] = 0.0;
                    $_SESSION['cart'][$newKey] = $entry;
                }
                unset($_SESSION['sale_request_token']);
                redirect($cartRedirectUrl);
            }
        }
    } elseif ($posAction === 'remove_line') {
        $key = post('key');
        unset($_SESSION['cart'][$key], $_SESSION['sale_request_token']);
        redirect($cartRedirectUrl);
    } elseif ($posAction === 'clear_cart') {
        $_SESSION['cart'] = [];
        unset($_SESSION['sale_request_token']);
        redirect('/pos/index.php');
    }
}

// ---- search / product resolution (GET, read-only) ----
$isAjax = isset($_GET['ajax']);
$q = trim((string) ($_GET['q'] ?? ''));

// ---- AJAX: "does this exactly match one scannable product?" ----
// A physical barcode scanner types the code then sends Enter, submitting
// the search form — this tells the frontend whether to auto-add straight
// to the cart (Skenario 1) or just show the normal results list for the
// cashier to pick from (Skenario 2). Read-only: the actual cart mutation
// still goes through the existing add_to_cart POST handler above.
if (($_GET['ajax'] ?? null) === 'scan') {
    header('Content-Type: application/json; charset=UTF-8');
    $result = ['action' => 'list'];

    if ($q !== '') {
        $exactStmt = $pdo->prepare(
            'SELECT id, name, current_stock_base FROM products
             WHERE active = 1 AND (code_normalized = ? OR barcode = ?) LIMIT 2'
        );
        $exactStmt->execute([normalize_code($q), $q]);
        $exactProducts = $exactStmt->fetchAll();

        if (count($exactProducts) === 1) {
            $product = $exactProducts[0];
            // Barcode is product-level only (no per-unit barcode column), so
            // a product with more than one sellable unit (e.g. PCS + PACK)
            // can't be resolved to a unit from the code alone. Auto-picks
            // the smallest unit (first in ASC conversion_factor order, same
            // ordering the search results already use) so the cart line is
            // always complete immediately — the cashier can still switch it
            // via the "ubah satuan" dropdown on that cart row if it's wrong.
            $unitStmt = $pdo->prepare(
                'SELECT id, unit_name, conversion_factor FROM product_units
                 WHERE product_id = ? AND active = 1 AND can_sell = 1 ORDER BY conversion_factor ASC'
            );
            $unitStmt->execute([$product['id']]);
            $units = $unitStmt->fetchAll();

            if ($units) {
                $unit = $units[0];
                $key = $product['id'] . '_' . $unit['id'];
                $existingQty = (int) ($_SESSION['cart'][$key]['qty'] ?? 0);
                $prospectiveBaseQty = ($existingQty + 1) * (int) $unit['conversion_factor'];

                if ($prospectiveBaseQty <= (int) $product['current_stock_base']) {
                    $result = [
                        'action' => 'add',
                        'product_id' => (int) $product['id'],
                        'unit_id' => (int) $unit['id'],
                    ];
                } else {
                    $result = [
                        'action' => 'list',
                        'warning' => 'Stok "' . $product['name'] . '" tidak cukup (sisa ' . (int) $product['current_stock_base'] . ').',
                    ];
                }
            } else {
                $result = [
                    'action' => 'list',
                    'warning' => 'Produk "' . $product['name'] . '" belum punya satuan yang bisa dijual.',
                ];
            }
        }
    }

    echo json_encode($result);
    exit;
}

$cartLines = get_cart_lines_with_details($pdo, $_SESSION['cart']);
$totals = cart_totals($cartLines);
$cashSessionActiveNow = get_active_cash_session($pdo) !== null;
$hasInvalidCart = false;
foreach ($cartLines as $l) {
    if (!$l['valid'] || !$l['stock_ok']) {
        $hasInvalidCart = true;
    }
}
// POS's own payment form only offers these 5, in this exact order — the
// other methods in PAYMENT_METHOD_LABELS still exist for historical
// sales/reports, this just keeps the pills on the register screen short.
$posMethodKeys = ['cash', 'qris', 'transfer', 'ovo', 'gopay'];
$paymentMethods = array_combine($posMethodKeys, array_map(
    static fn ($key) => PAYMENT_METHOD_LABELS[$key],
    $posMethodKeys
));
$quickCashAmounts = pos_quick_cash_amounts($totals['total']);

// One row per sellable UNIT (not per product) — a product sold in both PCS
// and SLOP shows as two independent, directly-addable rows, matching the
// "+ per row" interaction (no separate unit-picker step in between).
$posResults = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare(
        'SELECT p.id AS product_id, p.code, p.name, p.current_stock_base, p.low_stock_threshold_base,
                pu.id AS unit_id, pu.unit_name, pu.selling_price
         FROM products p JOIN product_units pu ON pu.product_id = p.id
         WHERE p.active = 1 AND pu.active = 1 AND pu.can_sell = 1
           AND (p.code LIKE ? OR p.barcode LIKE ? OR p.name LIKE ?)
         ORDER BY p.name, pu.conversion_factor ASC LIMIT 40'
    );
    $stmt->execute([$like, $like, $like]);
    $posResults = $stmt->fetchAll();
}

// The search-result table (or "not found" message) is buffered separately
// so search-as-you-type can swap just this part in via AJAX, without
// reloading the whole POS page (which would also be visually disruptive
// mid-transaction).
$posResultsSubtitle = 'Belum ada pencarian';
if ($q !== '') {
    $posResultsSubtitle = $posResults ? count($posResults) . ' produk' : 'Tidak ditemukan';
}

// Rows are stacked (name+price on one line, code+unit badges on the next)
// rather than a 4-column table — this card only gets ~25% of the row's
// width in the 3-column Kasir layout (see .pos-main-columns), nowhere near
// enough for Kode/Nama Produk/Unit/Harga to each hold their own column
// without Nama Produk shrinking back into the 1-character-ellipsis bug from
// before. Giving Nama Produk its own full-width line — sharing it only with
// the compact, never-wrapping Harga — is what actually guarantees it stays
// readable regardless of how narrow the card ends up.
ob_start();
?>
<div class="card-title-row">
  <span class="card-title-icon-box"><?= partambus_icon('bag', 18) ?></span>
  <div>
    <strong style="font-size:16px">Produk Ditemukan</strong>
    <p class="pos-card-subtitle"><?= e($posResultsSubtitle) ?></p>
  </div>
</div>
<div class="pos-scroll-list" id="pos-search-results-scroll">
<?php if ($q !== ''): ?>
  <?php if (!$posResults): ?>
    <div class="flash flash-warning" style="margin-top:10px">
      Produk dengan kode/kata kunci "<?= e($q) ?>" tidak ditemukan. Coba cek lagi kodenya atau kata kuncinya.
    </div>
  <?php else: ?>
    <div class="pos-results-list">
      <?php foreach ($posResults as $r): ?>
        <div class="pos-result-row" title="Stok: <?= (int) $r['current_stock_base'] ?>">
          <div class="pos-result-row-top">
            <span class="pos-result-name"><?= e($r['name']) ?></span>
            <span class="pos-result-price"><?= rupiah($r['selling_price']) ?></span>
          </div>
          <div class="pos-result-row-bottom">
            <span class="pos-code-badge"><?= e($r['code']) ?></span>
            <span class="pos-unit-badge"><?= e($r['unit_name']) ?></span>
            <span class="pos-result-arrow"><?= partambus_icon('chevron-down', 14) ?></span>
          </div>
          <form method="post" data-no-loading style="display:none">
            <?= csrf_field() ?>
            <input type="hidden" name="pos_action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= (int) $r['product_id'] ?>">
            <input type="hidden" name="unit_id" value="<?= (int) $r['unit_id'] ?>">
            <input type="hidden" name="qty" value="1">
            <input type="hidden" name="q" value="<?= e($q) ?>">
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php else: ?>
  <div class="pos-search-empty">
    <?= partambus_icon('search', 32) ?>
    <p>Scan barcode atau ketik kode/nama produk untuk mulai mencari.</p>
  </div>
<?php endif; ?>
<div class="pos-add-new-hint">
  Tidak menemukan produk? <a href="<?= APP_BASE_PATH ?>/products/create.php">Tambahkan produk baru</a>
</div>
</div>
<?php
$posSearchResultsHtml = ob_get_clean();

if ($isAjax) {
    header('Content-Type: text/html; charset=UTF-8');
    echo $posSearchResultsHtml;
    exit;
}

// Needed by the sticky payment panel's hidden field below — matches the
// same per-cart idempotency token pos/checkout.php has always used.
if (empty($_SESSION['sale_request_token'])) {
    $_SESSION['sale_request_token'] = bin2hex(random_bytes(24));
}

$posStep = $cartLines ? 2 : 1;

$posLowStockCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM products WHERE active = 1 AND current_stock_base <= low_stock_threshold_base'
)->fetchColumn();
$alerts = partambus_system_alerts($pdo, $posLowStockCount, is_owner());

ob_start();
?>
<div class="date-pill"><?= partambus_icon('users', 15) ?><span>Kasir: <?= e($actor['full_name']) ?></span></div>
<div class="dropdown">
  <button type="button" class="notif-bell-btn" data-dropdown-toggle="pos-notif-menu" aria-label="Notifikasi">
    <?= partambus_icon('bell', 18) ?>
    <?php if ($alerts): ?><span class="notif-badge"><?= count($alerts) ?></span><?php endif; ?>
  </button>
  <div class="dropdown-menu hidden" id="pos-notif-menu" style="min-width:240px">
    <?php if (!$alerts): ?>
      <div class="dropdown-item" style="cursor:default;color:var(--color-text-muted)">Tidak ada notifikasi</div>
    <?php else: ?>
      <?php foreach ($alerts as $alert): ?>
        <a class="dropdown-item" href="<?= e($alert['href']) ?>"><?= partambus_icon('warning', 15) ?> <?= e($alert['title']) ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php if (is_owner()): ?>
  <a href="<?= APP_BASE_PATH ?>/settings/index.php" class="notif-bell-btn" aria-label="Pengaturan" title="Pengaturan"><?= partambus_icon('gear', 18) ?></a>
<?php endif; ?>
<?php
$topbarExtra = ob_get_clean();

require __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="pos-stepper" id="pos-stepper">
  <div class="pos-step <?= $posStep === 1 ? 'pos-step-active' : '' ?>" data-pos-step="1"><span class="pos-step-num">1</span>Cari Produk</div>
  <div class="pos-step-line"></div>
  <div class="pos-step <?= $posStep === 2 ? 'pos-step-active' : '' ?>" data-pos-step="2"><span class="pos-step-num">2</span>Atur Keranjang</div>
  <div class="pos-step-line"></div>
  <div class="pos-step" data-pos-step="3"><span class="pos-step-num">3</span>Pilih Pembayaran</div>
  <div class="pos-step-line"></div>
  <div class="pos-step" data-pos-step="4"><span class="pos-step-num">4</span>Selesai</div>
</div>

<?php if (!$cashSessionActiveNow): ?>
  <div class="pos-cash-warning">
    <?= partambus_icon('warning', 20) ?>
    <span>Belum ada sesi kas yang dibuka. Pembayaran tunai baru bisa diterima setelah sesi kas dibuka. <a href="<?= APP_BASE_PATH ?>/cash/open.php"><strong>Buka sesi kas sekarang</strong></a> (metode pembayaran non-tunai tetap bisa dipakai tanpa ini).</span>
  </div>
<?php endif; ?>

<div class="pos-layout">
  <div class="pos-main">
    <div class="card">
      <form method="get" class="pos-search-form" id="pos-search-form" action="<?= APP_BASE_PATH ?>/pos/index.php" data-no-loading>
        <div class="pos-search-input-wrap">
          <input type="search" name="q" value="<?= e($q) ?>" id="pos-search-input" class="pos-search-input" placeholder="Scan barcode atau ketik kode/nama produk..." autofocus autocomplete="off">
        </div>
        <button type="submit" class="btn btn-large" aria-label="Cari"><?= partambus_icon('search', 18) ?></button>
      </form>
      <input type="hidden" id="pos-csrf-token" value="<?= e(csrf_token()) ?>">
    </div>

    <div class="pos-main-columns">
      <div class="card">
        <div id="pos-search-results" data-ajax-url="<?= e(APP_BASE_PATH . '/pos/index.php') ?>">
<?= $posSearchResultsHtml ?>
        </div>
      </div>

      <div class="card">
        <div class="pos-cart-header">
          <div class="pos-cart-header-title">
            <span class="pos-card-icon-box"><?= partambus_icon('cart', 20) ?></span>
            <div>
              <div class="pos-card-title-row">
                <strong style="font-size:16px">Keranjang</strong>
                <span class="pos-item-count-badge" id="pos-cart-count"><?= count($cartLines) ?> item</span>
              </div>
              <p class="pos-card-subtitle">Daftar produk yang akan dibeli</p>
            </div>
          </div>
          <button type="button" class="btn btn-outline-danger btn-small" id="pos-clear-cart-btn" <?= $cartLines ? '' : 'style="display:none"' ?>>
            <?= partambus_icon('trash', 14) ?> Kosongkan
          </button>
        </div>

        <div class="pos-scroll-list" style="margin-top:10px">
          <table class="pos-cart-table">
            <thead><tr><th>Kode</th><th>Nama Produk</th><th>Unit</th><th class="text-right">Qty</th><th class="text-right">Harga</th><th class="text-right">Diskon</th><th class="text-right">Subtotal</th><th></th></tr></thead>
            <tbody id="pos-cart-body">
            <?php if (!$cartLines): ?>
              <tr>
                <td colspan="8" class="pos-cart-empty-cell">
                  <div class="pos-cart-empty">
                    <?= partambus_icon('cart', 32) ?>
                    <p>Keranjang masih kosong. Scan atau cari produk untuk memulai.</p>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
            <?php foreach ($cartLines as $line): ?>
              <tr>
                <td><span class="pos-code-badge"><?= e((string) $line['code']) ?></span></td>
                <td class="pos-cart-name-cell">
                  <?= e((string) $line['name']) ?>
                  <?php if (!$line['valid']): ?><br><span class="pos-line-warning"><?= partambus_icon('warning', 12) ?> Tidak tersedia lagi</span><?php endif; ?>
                  <?php if ($line['valid'] && !$line['stock_ok']): ?><br><span class="pos-line-warning"><?= partambus_icon('warning', 12) ?> Stok kurang (sisa <?= (int) $line['current_stock_base'] ?>)</span><?php endif; ?>
                  <?php if ($line['valid'] && is_owner() && $line['below_cost']): ?><br><span class="badge badge-low-stock">di bawah modal</span><?php endif; ?>
                </td>
                <td>
                  <?php if (count($line['available_units']) > 1): ?>
                    <form method="post" class="pos-unit-pick-form" data-no-loading>
                      <?= csrf_field() ?>
                      <input type="hidden" name="pos_action" value="set_unit">
                      <input type="hidden" name="key" value="<?= e((string) $line['key']) ?>">
                      <input type="hidden" name="q" value="<?= e($q) ?>">
                      <select name="unit_id" class="pos-unit-pick-select" aria-label="Ubah satuan untuk <?= e((string) $line['name']) ?>">
                        <?php foreach ($line['available_units'] as $u): ?>
                          <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $line['unit_id'] ? 'selected' : '' ?>><?= e($u['unit_name']) ?> — <?= rupiah($u['selling_price']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </form>
                  <?php else: ?>
                    <?= e((string) $line['unit_name']) ?>
                  <?php endif; ?>
                </td>
                <td class="text-right">
                  <div class="qty-stepper">
                    <form method="post" data-no-loading>
                      <?= csrf_field() ?>
                      <input type="hidden" name="pos_action" value="update_line">
                      <input type="hidden" name="key" value="<?= e((string) $line['key']) ?>">
                      <input type="hidden" name="qty" value="<?= max(1, (int) $line['qty_unit'] - 1) ?>">
                      <input type="hidden" name="discount_amount" value="<?= (int) $line['discount_amount'] ?>">
                      <input type="hidden" name="q" value="<?= e($q) ?>">
                      <button type="submit" class="qty-stepper-btn" <?= (int) $line['qty_unit'] <= 1 ? 'disabled' : '' ?> aria-label="Kurangi jumlah">&minus;</button>
                    </form>
                    <form method="post" class="qty-stepper-input-form" data-no-loading>
                      <?= csrf_field() ?>
                      <input type="hidden" name="pos_action" value="update_line">
                      <input type="hidden" name="key" value="<?= e((string) $line['key']) ?>">
                      <input type="hidden" name="discount_amount" value="<?= (int) $line['discount_amount'] ?>">
                      <input type="hidden" name="q" value="<?= e($q) ?>">
                      <input type="number" name="qty" value="<?= (int) $line['qty_unit'] ?>" min="1" step="1" class="qty-stepper-input" aria-label="Jumlah">
                    </form>
                    <form method="post" data-no-loading>
                      <?= csrf_field() ?>
                      <input type="hidden" name="pos_action" value="update_line">
                      <input type="hidden" name="key" value="<?= e((string) $line['key']) ?>">
                      <input type="hidden" name="qty" value="<?= (int) $line['qty_unit'] + 1 ?>">
                      <input type="hidden" name="discount_amount" value="<?= (int) $line['discount_amount'] ?>">
                      <input type="hidden" name="q" value="<?= e($q) ?>">
                      <button type="submit" class="qty-stepper-btn" aria-label="Tambah jumlah">+</button>
                    </form>
                  </div>
                </td>
                <td class="text-right"><?= rupiah($line['unit_price']) ?></td>
                <td class="text-right">
                  <form method="post" class="pos-discount-form" data-no-loading>
                    <?= csrf_field() ?>
                    <input type="hidden" name="pos_action" value="update_line">
                    <input type="hidden" name="key" value="<?= e((string) $line['key']) ?>">
                    <input type="hidden" name="qty" value="<?= (int) $line['qty_unit'] ?>">
                    <input type="hidden" name="q" value="<?= e($q) ?>">
                    <input type="number" name="discount_amount" value="<?= (int) $line['discount_amount'] ?>" min="0" step="1" class="pos-discount-input" title="Diskon (Rp)" aria-label="Diskon (Rp)">
                  </form>
                </td>
                <td class="text-right"><?= rupiah($line['line_total']) ?></td>
                <td>
                  <form method="post" data-confirm="Hapus item ini dari keranjang?" data-no-loading>
                    <?= csrf_field() ?>
                    <input type="hidden" name="pos_action" value="remove_line">
                    <input type="hidden" name="key" value="<?= e((string) $line['key']) ?>">
                    <input type="hidden" name="q" value="<?= e($q) ?>">
                    <button type="submit" class="action-btn action-btn-danger" aria-label="Hapus item" title="Hapus item">
                      <?= partambus_icon('trash', 13) ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="pos-col-summary">
    <div class="card pos-summary-panel" id="pos-summary-panel"
         data-checkout-url="<?= e(APP_BASE_PATH . '/pos/checkout.php') ?>"
         data-reset-url="<?= e(APP_BASE_PATH . '/pos/index.php') ?>">
      <div class="pos-summary-header">
        <span class="pos-summary-header-icon"><?= partambus_icon('invoice', 16) ?></span>
        <strong style="font-size:16px">Ringkasan Pembayaran</strong>
      </div>

      <?php
      // The panel itself (totals, payment methods, cash input) always
      // renders — only the "Bayar Sekarang" button is disabled when there's
      // nothing to pay or the cart has a problem (the banner below already
      // explains it).
      $payBlockedSilently = !$cartLines || $hasInvalidCart;
      ?>
      <?php if ($hasInvalidCart): ?>
        <p class="flash flash-error" style="margin-top:12px">Ada item di keranjang yang sudah tidak valid atau stoknya kurang. Check keranjang sebelum membayar.</p>
      <?php endif; ?>
        <div id="pos-checkout-form-view">
          <div class="pos-summary-lines">
            <div class="pos-summary-line"><span>Subtotal (<?= count($cartLines) ?> item)</span><span><?= rupiah($totals['subtotal']) ?></span></div>
            <div class="pos-summary-line"><span>Diskon</span><span>-<?= rupiah($totals['discount_total']) ?></span></div>
          </div>
          <div class="pos-summary-total">
            <span>TOTAL</span>
            <span class="pos-summary-total-amount"><?= rupiah($totals['total']) ?></span>
          </div>

          <div id="pos-checkout-errors"></div>

          <form method="post" id="pos-checkout-form" novalidate data-loading-message="Memproses transaksi...">
            <?= csrf_field() ?>
            <input type="hidden" name="request_token" value="<?= e($_SESSION['sale_request_token']) ?>">

            <div class="form-group">
              <label>Metode Pembayaran</label>
              <?php if (!$cashSessionActiveNow): ?>
                <p class="flash flash-warning" style="margin-bottom:8px;font-size:12px">Tunai tidak bisa dipilih: belum ada sesi kas aktif.</p>
              <?php endif; ?>
              <?php $firstChecked = $cashSessionActiveNow ? 'cash' : 'qris'; ?>
              <div class="pos-method-pills">
                <?php foreach ($paymentMethods as $key => $label): ?>
                  <label class="pos-method-pill <?= ($key === 'cash' && !$cashSessionActiveNow) ? 'pos-method-pill-disabled' : '' ?>">
                    <input type="radio" name="method" value="<?= e($key) ?>" data-method-radio required
                      <?= ($key === 'cash' && !$cashSessionActiveNow) ? 'disabled' : '' ?>
                      <?= $key === $firstChecked ? 'checked' : '' ?>>
                    <?= e($label) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="form-group" id="cash-field">
              <label for="cash_received">Dibayar</label>
              <div class="pos-cash-input-row">
                <input type="text" inputmode="numeric" id="cash_received" name="cash_received" class="pos-cash-input" data-pos-total="<?= (int) ceil($totals['total']) ?>" autocomplete="off" placeholder="0">
                <button type="button" class="pos-calc-toggle-btn" id="pos-numpad-toggle" aria-label="Buka kalkulator" title="Buka kalkulator">
                  <?= partambus_icon('calculator', 18) ?>
                </button>
              </div>
              <div class="cash-quick-buttons">
                <?php foreach ($quickCashAmounts as $i => $amount): ?>
                  <button type="button" class="cash-quick-btn" data-cash-amount="<?= $amount ?>"><?= $i === 0 ? 'Uang Pas' : '' ?> <?= rupiah($amount) ?></button>
                <?php endforeach; ?>
              </div>
              <div class="numpad hidden" id="pos-numpad">
                <button type="button" class="numpad-btn" data-numpad-key="7">7</button>
                <button type="button" class="numpad-btn" data-numpad-key="8">8</button>
                <button type="button" class="numpad-btn" data-numpad-key="9">9</button>
                <button type="button" class="numpad-btn" data-numpad-key="4">4</button>
                <button type="button" class="numpad-btn" data-numpad-key="5">5</button>
                <button type="button" class="numpad-btn" data-numpad-key="6">6</button>
                <button type="button" class="numpad-btn" data-numpad-key="1">1</button>
                <button type="button" class="numpad-btn" data-numpad-key="2">2</button>
                <button type="button" class="numpad-btn" data-numpad-key="3">3</button>
                <button type="button" class="numpad-btn numpad-btn-clear" data-numpad-key="clear">C</button>
                <button type="button" class="numpad-btn" data-numpad-key="0">0</button>
                <button type="button" class="numpad-btn numpad-btn-back" data-numpad-key="back" aria-label="Hapus satu angka">&larr;</button>
              </div>
              <div id="change-preview" class="change-preview hidden"></div>
            </div>

            <button type="submit"
              class="btn btn-xlarge pos-pay-btn<?= $payBlockedSilently ? ' pos-pay-btn-disabled' : '' ?>"
              id="pos-checkout-confirm-btn"
              title="Bayar Sekarang (F4)"
              <?= $payBlockedSilently ? 'disabled' : '' ?>>
              Bayar Sekarang
            </button>
          </form>
        </div>

        <div id="pos-checkout-success-view" class="hidden">
          <div class="checkout-success">
            <svg class="success-checkmark" viewBox="0 0 52 52" aria-hidden="true">
              <circle class="success-checkmark-circle" cx="26" cy="26" r="24"></circle>
              <path class="success-checkmark-check" d="M14.1 27.2l7.1 7.2 16.7-16.8"></path>
            </svg>
            <p class="pos-success-title">Transaksi Berhasil!</p>
            <p id="pos-checkout-success-number" class="text-muted" style="margin:0"></p>

            <div class="pos-success-cards" id="pos-checkout-success-cards">
              <div class="pos-success-card pos-success-card-blue">
                <span class="pos-success-card-icon"><?= partambus_icon('wallet', 18) ?></span>
                <div>
                  <div class="pos-success-card-label">Total Pembayaran</div>
                  <div class="pos-success-card-value" id="pos-checkout-success-total"></div>
                </div>
              </div>
              <div class="pos-success-card pos-success-card-green hidden" id="pos-checkout-success-change">
                <span class="pos-success-card-icon"><?= partambus_icon('money', 18) ?></span>
                <div>
                  <div class="pos-success-card-label">Kembalian</div>
                  <div class="pos-success-card-value" id="pos-checkout-success-change-amount"></div>
                </div>
              </div>
            </div>

            <div class="btn-row" style="justify-content:center;flex-direction:column">
              <button type="button" class="btn btn-secondary" id="pos-checkout-receipt-btn" data-base-url="<?= e(APP_BASE_PATH . '/sales/view.php') ?>" style="width:100%;text-align:center">
                <?= partambus_icon('printer', 15) ?> Lihat Struk
              </button>
              <button type="button" class="btn btn-xlarge" id="pos-checkout-new-sale-btn" style="width:100%">
                <?= partambus_icon('plus-circle', 18) ?> Transaksi Baru
              </button>
            </div>
          </div>
        </div>
    </div>
  </div>
</div>


<form method="post" id="pos-clear-cart-form" style="display:none" data-no-loading>
  <?= csrf_field() ?>
  <input type="hidden" name="pos_action" value="clear_cart">
</form>

<div class="modal-overlay hidden" id="pos-clear-cart-modal">
  <div class="modal-box" style="max-width:400px">
    <strong style="font-size:16px">Kosongkan Keranjang?</strong>
    <p class="text-muted" style="margin-top:8px">Yakin ingin mengosongkan keranjang? Semua item yang belum dibayar akan dihapus.</p>
    <div class="btn-row" style="justify-content:flex-end">
      <button type="button" class="btn btn-secondary" data-modal-cancel>Tidak</button>
      <button type="button" class="btn btn-danger" id="pos-clear-cart-confirm-btn">Ya, Kosongkan</button>
    </div>
  </div>
</div>

<div class="modal-overlay hidden" id="pos-receipt-modal">
  <div class="modal-box" style="max-width:460px">
    <strong style="font-size:16px">Preview Struk</strong>
    <p class="text-muted" style="margin-top:2px;font-size:12px">Struk transaksi yang akan dicetak</p>

    <div id="pos-receipt-modal-body" style="margin-top:12px">
      <p class="text-muted">Memuat...</p>
    </div>

    <div class="form-group" style="margin-top:14px">
      <label for="pos-receipt-wa-phone">Kirim ke WhatsApp</label>
      <div class="pos-receipt-wa-row">
        <input type="text" id="pos-receipt-wa-phone" inputmode="numeric" autocomplete="off" placeholder="0812xxxxxxxx">
        <button type="button" class="btn" id="pos-receipt-wa-send-btn">Kirim ke WhatsApp</button>
      </div>
      <p class="form-hint" id="pos-receipt-wa-error" style="display:none;color:var(--color-danger)"></p>
      <p class="form-hint">Chat WhatsApp akan terbuka dengan pesan struk sudah terisi — kamu tetap perlu klik "Kirim" di WhatsApp untuk mengirimkannya.</p>
    </div>

    <div class="btn-row" style="justify-content:flex-end;margin-top:14px">
      <button type="button" class="btn btn-secondary" data-modal-cancel>Tutup</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
