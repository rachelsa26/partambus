<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_login();
$pageTitle = 'Riwayat Penjualan';

$q = trim((string) ($_GET['q'] ?? ''));
$method = trim((string) ($_GET['method'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$productId = (int) ($_GET['product_id'] ?? 0);
$cashierId = (int) ($_GET['cashier_id'] ?? 0);
$hasDateFilter = ($_GET['from'] ?? '') !== '' || ($_GET['to'] ?? '') !== '';
$range = $hasDateFilter ? resolve_date_range('custom', (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? '')) : null;

$perPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50], true)) {
    $perPage = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$whereSql = ' WHERE 1=1';
$params = [];

if (!is_owner()) {
    $whereSql .= ' AND s.cashier_id = ?';
    $params[] = $actor['id'];
} elseif ($cashierId > 0) {
    $whereSql .= ' AND s.cashier_id = ?';
    $params[] = $cashierId;
}
if ($q !== '') {
    $whereSql .= ' AND s.sale_number LIKE ?';
    $params[] = '%' . $q . '%';
}
if ($method !== '') {
    $whereSql .= ' AND pay.method = ?';
    $params[] = $method;
}
if (in_array($status, ['completed', 'void'], true)) {
    $whereSql .= ' AND s.status = ?';
    $params[] = $status;
}
if ($productId > 0) {
    $whereSql .= ' AND EXISTS (SELECT 1 FROM sale_items si WHERE si.sale_id = s.id AND si.product_id = ?)';
    $params[] = $productId;
}
if ($range) {
    $whereSql .= ' AND s.created_at BETWEEN ? AND ?';
    $params[] = $range['from'];
    $params[] = $range['to'];
}

$countStmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT s.id) FROM sales s JOIN users u ON u.id = s.cashier_id LEFT JOIN payments pay ON pay.sale_id = s.id' . $whereSql
);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT DISTINCT s.id, s.sale_number, s.status, s.total, s.created_at, u.full_name AS cashier_name, pay.method
     FROM sales s
     JOIN users u ON u.id = s.cashier_id
     LEFT JOIN payments pay ON pay.sale_id = s.id' . $whereSql . '
     ORDER BY s.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$methodLabels = PAYMENT_METHOD_LABELS;
$statusLabels = ['completed' => 'Selesai', 'void' => 'Void'];

// ---- "Riwayat Transaksi" table + pagination — buffered separately so the
// filter panel (date presets, all fields) can refresh just this part via
// AJAX, same fetch-and-swap pattern as the dashboard's range filter. ----
ob_start();
?>
<strong style="font-size:16px">Riwayat Transaksi</strong>
<table style="margin-top:12px">
  <thead><tr><th>No. Transaksi</th><th>Waktu</th><th>Kasir</th><th>Metode</th><th class="text-right">Total</th><th>Status</th><th>Aksi</th></tr></thead>
  <tbody>
  <?php foreach ($sales as $s): ?>
    <tr>
      <td><?= e($s['sale_number']) ?></td>
      <td class="text-muted"><?= e(fmt_date($s['created_at'])) ?></td>
      <td><?= e($s['cashier_name']) ?></td>
      <td><?= e($methodLabels[$s['method']] ?? '-') ?></td>
      <td class="text-right"><?= rupiah($s['total']) ?></td>
      <td>
        <?php if ($s['status'] === 'completed'): ?>
          <span class="badge badge-active"><?= partambus_icon('check', 11) ?> <?= e($statusLabels['completed']) ?></span>
        <?php else: ?>
          <span class="badge badge-danger"><?= e($statusLabels[$s['status']] ?? $s['status']) ?></span>
        <?php endif; ?>
      </td>
      <td><a class="btn btn-outline-primary btn-small" href="<?= APP_BASE_PATH ?>/sales/view.php?id=<?= (int) $s['id'] ?>">Lihat</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$sales): ?>
    <tr>
      <td colspan="7">
        <div class="empty-state">
          <div class="empty-state-icon">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          </div>
          <p class="empty-state-title">Tidak ada transaksi yang cocok</p>
          <p class="empty-state-hint">Coba ubah atau kosongkan filter di atas.</p>
        </div>
      </td>
    </tr>
  <?php endif; ?>
  </tbody>
</table>

<?php if ($totalCount > 0): ?>
<?php
$rangeStart = $offset + 1;
$rangeEnd = min($offset + $perPage, $totalCount);
$baseParams = ['q' => $q, 'method' => $method, 'status' => $status, 'cashier_id' => $cashierId ?: '', 'from' => $range['from_date'] ?? '', 'to' => $range['to_date'] ?? ''];
$pageUrl = static function (int $targetPage, int $targetPerPage) use ($baseParams) {
    $params = array_filter(array_merge($baseParams, ['per_page' => $targetPerPage, 'page' => $targetPage]), static fn ($v) => $v !== '' && $v !== 0);
    return APP_BASE_PATH . '/sales/index.php?' . http_build_query($params);
};
?>
<div class="sales-pagination">
  <span class="text-muted text-small">Menampilkan <?= $rangeStart ?>-<?= $rangeEnd ?> dari <?= $totalCount ?> transaksi</span>
  <div class="sales-pagination-controls">
    <select class="sales-per-page-select">
      <?php foreach ([10, 25, 50] as $opt): ?>
        <option value="<?= e($pageUrl(1, $opt)) ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?> / halaman</option>
      <?php endforeach; ?>
    </select>
    <a class="pagination-btn pagination-btn-prev <?= $page <= 1 ? 'pagination-btn-disabled' : '' ?>" href="<?= $page > 1 ? e($pageUrl($page - 1, $perPage)) : '#' ?>" aria-label="Halaman sebelumnya"><?= partambus_icon('arrow-right', 14) ?></a>
    <span class="text-small"><?= $page ?> / <?= $totalPages ?></span>
    <a class="pagination-btn <?= $page >= $totalPages ? 'pagination-btn-disabled' : '' ?>" href="<?= $page < $totalPages ? e($pageUrl($page + 1, $perPage)) : '#' ?>" aria-label="Halaman berikutnya"><?= partambus_icon('arrow-right', 14) ?></a>
  </div>
</div>
<?php endif; ?>
<?php
$salesResultsHtml = ob_get_clean();

if (($_GET['ajax'] ?? null) === '1') {
    header('Content-Type: text/html; charset=UTF-8');
    echo $salesResultsHtml;
    exit;
}

$cashiers = is_owner() ? $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll() : [];

/** Small decorative trend line (no axes/labels) for the summary KPI cards. */
function sales_sparkline_svg(array $values, string $color, int $width = 84, int $height = 28): string
{
    $count = count($values);
    if ($count < 2) {
        return '';
    }
    $max = max($values);
    $min = min($values);
    $range = ($max - $min) ?: 1;
    $points = [];
    foreach (array_values($values) as $i => $v) {
        $x = ($i / ($count - 1)) * $width;
        $y = $height - (($v - $min) / $range) * ($height - 4) - 2;
        $points[] = round($x, 1) . ',' . round($y, 1);
    }
    return '<svg width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" class="sales-sparkline" aria-hidden="true">'
        . '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="' . e($color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>'
        . '</svg>';
}

$todayKpi = null;
$sparklineTx = [];
$sparklineRevenue = [];
if (is_owner()) {
    $todayRange = resolve_date_range('today');
    $todayStmt = $pdo->prepare(
        'SELECT COUNT(*) AS tx_count, COALESCE(SUM(total),0) AS revenue
         FROM sales WHERE status = "completed" AND created_at BETWEEN ? AND ?'
    );
    $todayStmt->execute([$todayRange['from'], $todayRange['to']]);
    $todayKpi = $todayStmt->fetch();

    // Last 7 days (today included), one bucket per day, zero-filled so the
    // sparkline still draws a sensible line even on quiet days.
    $sparkStmt = $pdo->prepare(
        "SELECT DATE(created_at) AS d, COUNT(*) AS tx_count, COALESCE(SUM(total),0) AS revenue
         FROM sales WHERE status = 'completed' AND created_at >= ? GROUP BY DATE(created_at)"
    );
    $sevenDaysAgo = (new DateTimeImmutable('today'))->modify('-6 days');
    $sparkStmt->execute([$sevenDaysAgo->format('Y-m-d 00:00:00')]);
    $byDate = [];
    foreach ($sparkStmt->fetchAll() as $row) {
        $byDate[$row['d']] = $row;
    }
    for ($i = 6; $i >= 0; $i--) {
        $d = (new DateTimeImmutable('today'))->modify("-{$i} days")->format('Y-m-d');
        $sparklineTx[] = (int) ($byDate[$d]['tx_count'] ?? 0);
        $sparklineRevenue[] = (float) ($byDate[$d]['revenue'] ?? 0);
    }
}

$topbarSubtitle = 'Lihat dan kelola semua transaksi penjualan yang telah dilakukan.';

ob_start();
?>
<button type="button" class="btn btn-secondary" disabled title="Segera hadir">
  <?= partambus_icon('download', 15) ?> Export
</button>
<?php
$topbarExtra = ob_get_clean();

require __DIR__ . '/../includes/header.php';
?>

<?php if ($todayKpi): ?>
<div class="card-grid">
  <div class="card sales-kpi-card">
    <div class="sales-kpi-icon sales-kpi-icon-blue"><?= partambus_icon('cart', 20) ?></div>
    <div class="sales-kpi-body">
      <div class="text-muted">Total Transaksi Hari Ini</div>
      <div class="sales-kpi-value"><?= (int) $todayKpi['tx_count'] ?></div>
    </div>
    <?= sales_sparkline_svg($sparklineTx, '#2563EB') ?>
  </div>
  <div class="card sales-kpi-card">
    <div class="sales-kpi-icon sales-kpi-icon-green"><?= partambus_icon('money', 20) ?></div>
    <div class="sales-kpi-body">
      <div class="text-muted">Total Omzet Hari Ini</div>
      <div class="sales-kpi-value"><?= rupiah($todayKpi['revenue']) ?></div>
    </div>
    <?= sales_sparkline_svg($sparklineRevenue, '#22C55E') ?>
  </div>
</div>
<?php endif; ?>

<div class="card filter-panel">
  <div class="filter-panel-header">
    <div class="filter-panel-title-row">
      <span class="filter-panel-icon-box"><?= partambus_icon('filter', 16) ?></span>
      <div>
        <strong style="font-size:16px">Filter Transaksi</strong>
        <p class="pos-card-subtitle">Cari transaksi berdasarkan nomor, status, metode, kasir, dan rentang tanggal.</p>
      </div>
    </div>
    <div class="date-preset-group">
      <button type="button" class="date-preset-btn" data-date-preset="today">Hari Ini</button>
      <button type="button" class="date-preset-btn" data-date-preset="7d">7 Hari</button>
      <button type="button" class="date-preset-btn" data-date-preset="30d">30 Hari</button>
      <button type="button" class="date-preset-btn" data-date-preset="this_month">Bulan Ini</button>
    </div>
  </div>

  <form method="get" id="sales-filter-form">
    <div class="filter-panel-divider"></div>
    <div class="filter-grid">
      <div class="form-group">
        <label for="filter-q">No. Transaksi</label>
        <div class="input-icon-wrap">
          <?= partambus_icon('search', 15) ?>
          <input type="search" id="filter-q" name="q" value="<?= e($q) ?>" placeholder="mis. SL-20260822-0001">
        </div>
      </div>
      <div class="form-group">
        <label for="filter-status">Status</label>
        <select id="filter-status" name="status">
          <option value="">Semua status</option>
          <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Selesai</option>
          <option value="void" <?= $status === 'void' ? 'selected' : '' ?>>Void</option>
        </select>
      </div>
      <div class="form-group">
        <label for="filter-method">Metode</label>
        <select id="filter-method" name="method">
          <option value="">Semua metode</option>
          <?php foreach ($methodLabels as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $method === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (is_owner()): ?>
      <div class="form-group">
        <label for="filter-cashier">Kasir</label>
        <select id="filter-cashier" name="cashier_id">
          <option value="">Semua kasir</option>
          <?php foreach ($cashiers as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $cashierId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group date-range-group">
        <div class="date-range-field">
          <label for="filter-from">Dari Tanggal</label>
          <div class="input-icon-wrap">
            <?= partambus_icon('calendar', 15) ?>
            <input type="date" id="filter-from" name="from" value="<?= e($range['from_date'] ?? '') ?>">
          </div>
        </div>
        <span class="date-range-arrow"><?= partambus_icon('arrow-right', 14) ?></span>
        <div class="date-range-field">
          <label for="filter-to">Sampai Tanggal</label>
          <div class="input-icon-wrap">
            <?= partambus_icon('calendar', 15) ?>
            <input type="date" id="filter-to" name="to" value="<?= e($range['to_date'] ?? '') ?>">
          </div>
        </div>
      </div>
    </div>
    <div class="filter-panel-divider"></div>
    <div class="btn-row">
      <button type="button" class="btn btn-secondary" id="sales-filter-reset-btn"><?= partambus_icon('refresh', 15) ?> Reset</button>
    </div>
  </form>
</div>

<div class="card" id="sales-results-region" data-ajax-url="<?= e(APP_BASE_PATH . '/sales/index.php') ?>">
<?= $salesResultsHtml ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
