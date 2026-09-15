<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_login();
$pageTitle = 'Produk';

$isAjax = isset($_GET['ajax']);
$search = trim((string) ($_GET['q'] ?? ''));
$showInactive = isset($_GET['show_inactive']);
$noWacOnly = is_owner() && isset($_GET['no_wac']);

$allowedPerPage = [20, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 50;
}

$whereSql = ' WHERE 1=1';
$params = [];

if ($search !== '') {
    $whereSql .= ' AND (p.code LIKE ? OR p.barcode LIKE ? OR p.name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if (!$showInactive) {
    $whereSql .= ' AND p.active = 1';
}
if ($noWacOnly) {
    $whereSql .= ' AND (p.current_wac IS NULL OR p.current_wac = 0)';
}

$sortColumns = ['name' => 'p.name', 'stock' => 'p.current_stock_base'];
if (is_owner()) {
    $sortColumns['wac'] = 'p.current_wac';
}
$sortKey = (string) ($_GET['sort'] ?? 'name');
if (!array_key_exists($sortKey, $sortColumns)) {
    $sortKey = 'name';
}
$sortDir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$orderSql = ' ORDER BY ' . $sortColumns[$sortKey] . ' ' . strtoupper($sortDir) . ', p.id ASC';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM products p' . $whereSql);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));

$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT p.id, p.code, p.barcode, p.name, p.base_unit_name, p.current_stock_base, p.low_stock_threshold_base, p.current_wac, p.active
     FROM products p'
    . $whereSql
    . $orderSql
    . ' LIMIT ? OFFSET ?'
);
$i = 1;
foreach ($params as $p) {
    $stmt->bindValue($i++, $p);
}
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i++, $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

// Every unit + price for just the products on this page, grouped in PHP —
// cheaper than a per-row query, and keeps formatting (bold base unit, "isi
// N" suffix) simple to control here rather than via SQL string-building.
$unitsByProduct = [];
$productIds = array_column($products, 'id');
if ($productIds) {
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $unitStmt = $pdo->prepare(
        "SELECT product_id, unit_name, conversion_factor, selling_price, is_base
         FROM product_units WHERE product_id IN ($placeholders) ORDER BY conversion_factor ASC"
    );
    $unitStmt->execute($productIds);
    foreach ($unitStmt->fetchAll() as $u) {
        $unitsByProduct[(int) $u['product_id']][] = $u;
    }
}

/**
 * Builds a products/index.php URL from the current filter set (q,
 * show_inactive, no_wac) plus $overrides for whichever of page/sort/dir this
 * particular link is changing. Centralizing this means a future new filter
 * only needs to be added here once, not at every call site.
 *
 * @param array{q:string, show_inactive:bool, no_wac:bool} $filters
 */
function products_url(array $overrides, array $filters, int $perPage): string
{
    $qs = ['per_page' => $perPage];
    if ($filters['q'] !== '') {
        $qs['q'] = $filters['q'];
    }
    if ($filters['show_inactive']) {
        $qs['show_inactive'] = '1';
    }
    if ($filters['no_wac']) {
        $qs['no_wac'] = '1';
    }
    $qs = array_merge($qs, $overrides);
    return APP_BASE_PATH . '/products/index.php?' . http_build_query($qs);
}

/**
 * Builds a column-header sort link with a 3-click cycle: not-yet-active ->
 * ascending -> descending -> back to the default sort (name asc), rather
 * than only ever toggling between asc/desc.
 */
function products_sort_url(string $column, string $currentSort, string $currentDir, array $filters, int $perPage): string
{
    if ($currentSort !== $column) {
        $nextSort = $column;
        $nextDir = 'asc';
    } elseif ($currentDir === 'asc') {
        $nextSort = $column;
        $nextDir = 'desc';
    } else {
        $nextSort = 'name';
        $nextDir = 'asc';
    }
    return products_url(['page' => 1, 'sort' => $nextSort, 'dir' => $nextDir], $filters, $perPage);
}

/**
 * Sort-direction icon for a column header. Sortable-but-inactive columns
 * still get a faint neutral up/down icon (not nothing) so a first-time user
 * can tell the header is clickable before ever sorting by it; the active
 * column gets a bold single arrow in the current direction instead.
 */
function products_sort_indicator(string $column, string $currentSort, string $currentDir): string
{
    if ($column !== $currentSort) {
        return ' <svg class="sort-icon sort-icon-neutral" width="10" height="14" viewBox="0 0 10 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4 5 1 8 4"></path><path d="M2 10 5 13 8 10"></path></svg>';
    }
    $arrowPath = $currentDir === 'asc' ? 'M2 8 5 3 8 8' : 'M2 6 5 11 8 6';
    return ' <svg class="sort-icon sort-icon-active" width="10" height="14" viewBox="0 0 10 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="' . $arrowPath . '"></path></svg>';
}

$currentFilters = ['q' => $search, 'show_inactive' => $showInactive, 'no_wac' => $noWacOnly];

// Everything that can change from a live search keystroke — the result
// count line, the table, and pagination — is buffered separately so it can
// either be echoed as a standalone AJAX response (search-as-you-type) or
// embedded in the normal full-page render below.
ob_start();
?>
<?php if ($totalCount > 0): ?>
<p class="text-muted" style="margin-bottom:10px">
  Menampilkan <?= number_format($offset + 1, 0, ',', '.') ?>&ndash;<?= number_format(min($offset + $perPage, $totalCount), 0, ',', '.') ?> dari <?= number_format($totalCount, 0, ',', '.') ?> produk
</p>
<?php endif; ?>

<div class="card">
<table class="table-align-top">
  <thead>
    <tr>
      <th>Kode</th>
      <th class="sortable"><a href="<?= e(products_sort_url('name', $sortKey, $sortDir, $currentFilters, $perPage)) ?>">Nama<?= products_sort_indicator('name', $sortKey, $sortDir) ?></a></th>
      <th class="text-right sortable"><a href="<?= e(products_sort_url('stock', $sortKey, $sortDir, $currentFilters, $perPage)) ?>">Stok<?= products_sort_indicator('stock', $sortKey, $sortDir) ?></a></th>
      <?php if (is_owner()): ?>
        <th class="text-right sortable"><a href="<?= e(products_sort_url('wac', $sortKey, $sortDir, $currentFilters, $perPage)) ?>">Harga Modal<?= products_sort_indicator('wac', $sortKey, $sortDir) ?></a></th>
      <?php endif; ?>
      <th class="text-right">Harga</th>
      <th>Status</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($products as $p): ?>
    <?php $isLow = (int) $p['current_stock_base'] <= (int) $p['low_stock_threshold_base']; ?>
    <tr>
      <td><?= e($p['code']) ?></td>
      <td><?= e($p['name']) ?><?php if ($p['barcode']): ?><br><span class="text-muted"><?= e($p['barcode']) ?></span><?php endif; ?></td>
      <td class="text-right">
        <?= (int) $p['current_stock_base'] ?>
        <?php if ($isLow): ?><span class="badge badge-low-stock">rendah</span><?php endif; ?>
      </td>
      <?php if (is_owner()): ?>
        <td class="text-right">
          <?php if ($p['current_wac'] === null): ?>
            <span class="text-muted">belum ada</span>
          <?php elseif ((float) $p['current_wac'] === 0.0): ?>
            <span class="wac-warning" title="Harga pokok belum diisi">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
              Rp0
            </span>
          <?php else: ?>
            <?= rupiah($p['current_wac']) ?>
          <?php endif; ?>
        </td>
      <?php endif; ?>
      <td class="text-right" style="font-size:12px">
        <?php $rowUnits = $unitsByProduct[(int) $p['id']] ?? []; ?>
        <?php if (!$rowUnits): ?>
          <span class="text-muted">-</span>
        <?php endif; ?>
        <?php foreach ($rowUnits as $u): ?>
          <div>
            <?php if ($u['selling_price'] !== null): ?>
              <?php if ($u['is_base']): ?>
                <strong><?= rupiah($u['selling_price']) ?> /<?= e($u['unit_name']) ?></strong>
              <?php else: ?>
                <?= rupiah($u['selling_price']) ?> /<?= e($u['unit_name']) ?>
                <span class="text-muted">(isi <?= (int) $u['conversion_factor'] ?> <?= e($p['base_unit_name']) ?>)</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted"><?= $u['is_base'] ? '' : '(isi ' . (int) $u['conversion_factor'] . ' ' . e($p['base_unit_name']) . ') ' ?>belum ada harga /<?= e($u['unit_name']) ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </td>
      <td><span class="badge badge-<?= $p['active'] ? 'active' : 'inactive' ?>"><?= $p['active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
      <td>
        <?php if (is_owner()): ?>
        <div class="action-btn-group">
          <a href="<?= APP_BASE_PATH ?>/products/edit.php?id=<?= (int) $p['id'] ?>" class="action-btn action-btn-primary">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"></path></svg>
            Edit
          </a>
          <a href="<?= APP_BASE_PATH ?>/inventory/movements.php?product_id=<?= (int) $p['id'] ?>" class="action-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            Riwayat Stok
          </a>
          <button type="button" class="action-btn action-btn-danger" data-delete-trigger data-delete-id="<?= (int) $p['id'] ?>" data-delete-name="<?= e($p['name']) ?>">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
            Hapus
          </button>
        </div>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$products): ?>
    <tr><td colspan="7" class="empty-state">Belum ada produk yang cocok.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($totalCount > 0): ?>
<div class="btn-row" style="justify-content:center">
  <a class="btn btn-secondary btn-small<?= $page <= 1 ? ' disabled' : '' ?>"
     href="<?= $page > 1 ? e(products_url(['page' => $page - 1, 'sort' => $sortKey, 'dir' => $sortDir], $currentFilters, $perPage)) : '#' ?>"
     <?= $page <= 1 ? 'aria-disabled="true" onclick="return false;"' : '' ?>>&laquo; Sebelumnya</a>
  <span class="text-muted" style="font-size:13px">Halaman <?= $page ?> dari <?= $totalPages ?></span>
  <a class="btn btn-secondary btn-small<?= $page >= $totalPages ? ' disabled' : '' ?>"
     href="<?= $page < $totalPages ? e(products_url(['page' => $page + 1, 'sort' => $sortKey, 'dir' => $sortDir], $currentFilters, $perPage)) : '#' ?>"
     <?= $page >= $totalPages ? 'aria-disabled="true" onclick="return false;"' : '' ?>>Selanjutnya &raquo;</a>
  <?php if ($totalPages > 1): ?>
  <form method="get" class="jump-to-page">
    <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
    <?php if ($showInactive): ?><input type="hidden" name="show_inactive" value="1"><?php endif; ?>
    <?php if ($noWacOnly): ?><input type="hidden" name="no_wac" value="1"><?php endif; ?>
    <input type="hidden" name="per_page" value="<?= $perPage ?>">
    <input type="hidden" name="sort" value="<?= e($sortKey) ?>">
    <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
    <label for="jump-page-input" class="text-muted" style="font-size:13px">Lompat ke halaman:</label>
    <input type="number" id="jump-page-input" name="page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" style="width:70px">
    <button type="submit" class="btn btn-secondary btn-small">Pergi</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
$resultsHtml = ob_get_clean();

if ($isAjax) {
    header('Content-Type: text/html; charset=UTF-8');
    echo $resultsHtml;
    exit;
}

require __DIR__ . '/../includes/header.php';
?>

<form method="get" id="products-filter-form" action="<?= APP_BASE_PATH ?>/products/index.php">
  <div class="card" style="margin-bottom:16px">
    <div class="btn-row" style="margin-top:0; justify-content:space-between">
      <div class="pos-search-form" style="flex:1; max-width:520px">
        <input type="search" name="q" id="product-search-input" value="<?= e($search) ?>" class="pos-search-input" style="font-size:16px;padding:10px 14px" placeholder="Cari kode, barcode, atau nama produk...">
        <button type="submit" class="btn btn-secondary">Cari</button>
      </div>
      <?php if (is_owner()): ?>
      <div class="dropdown">
        <button type="button" class="btn" data-dropdown-toggle="add-product-menu">+ Tambah Produk</button>
        <div class="dropdown-menu hidden" id="add-product-menu">
          <a href="<?= APP_BASE_PATH ?>/products/create.php" class="dropdown-item">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"></path></svg>
            Tambah Manual
          </a>
          <a href="<?= APP_BASE_PATH ?>/products/import.php" class="dropdown-item">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
            Import Massal
          </a>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <details class="filter-panel" style="margin-top:14px" <?= ($showInactive || $noWacOnly || $perPage !== 50) ? 'open' : '' ?>>
      <summary>Filter Lanjutan</summary>
      <div class="filter-grid">
        <label class="checkbox-inline">
          <input type="checkbox" name="show_inactive" value="1" onchange="document.getElementById('products-page-input').value=1; this.form.submit()" <?= $showInactive ? 'checked' : '' ?>>
          Tampilkan nonaktif
        </label>
        <?php if (is_owner()): ?>
        <label class="checkbox-inline">
          <input type="checkbox" name="no_wac" value="1" onchange="document.getElementById('products-page-input').value=1; this.form.submit()" <?= $noWacOnly ? 'checked' : '' ?>>
          Tampilkan hanya produk tanpa Harga Modal
        </label>
        <?php endif; ?>
        <label class="checkbox-inline">
          per halaman:
          <select name="per_page" onchange="document.getElementById('products-page-input').value=1; this.form.submit()">
            <?php foreach ($allowedPerPage as $opt): ?>
              <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </details>
  </div>

  <input type="hidden" name="page" id="products-page-input" value="1">
  <input type="hidden" name="sort" value="<?= e($sortKey) ?>">
  <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
</form>

<div id="products-results" data-ajax-url="<?= e(APP_BASE_PATH . '/products/index.php') ?>">
<?= $resultsHtml ?>
</div>

<?php if (is_owner()): ?>
<form method="post" id="delete-product-form" action="<?= APP_BASE_PATH ?>/products/delete.php" style="display:none" data-loading-message="Menghapus produk...">
  <?= csrf_field() ?>
  <input type="hidden" name="product_id" id="delete-product-id" value="">
</form>

<div class="modal-overlay hidden" id="delete-product-modal">
  <div class="modal-box">
    <p>Yakin ingin menghapus produk <strong id="delete-product-name"></strong>? Tindakan ini tidak bisa dibatalkan.</p>
    <div class="btn-row">
      <button type="button" class="btn btn-secondary" data-modal-cancel>Batal</button>
      <button type="button" class="btn btn-danger" id="delete-product-confirm-btn">Ya, Hapus</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
