<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Laporan Performa Produk';
$backUrl = APP_BASE_PATH . '/reports/index.php';
$backLabel = 'Kembali ke Laporan';

$range = resolve_date_range((string) ($_GET['preset'] ?? 'today'), (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));

$stmt = $pdo->prepare(
    'SELECT si.product_id, si.product_code_snapshot AS code, si.product_name_snapshot AS name,
            SUM(si.qty_base) AS total_qty, SUM(si.line_total) AS total_revenue,
            SUM(si.cogs_total) AS total_cogs, SUM(CASE WHEN si.cogs_total IS NULL THEN 1 ELSE 0 END) AS unknown_cost_lines
     FROM sale_items si JOIN sales s ON s.id = si.sale_id
     WHERE s.status = "completed" AND s.created_at BETWEEN ? AND ?
     GROUP BY si.product_id, si.product_code_snapshot, si.product_name_snapshot'
);
$stmt->execute([$range['from'], $range['to']]);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['gross_profit'] = $r['total_cogs'] !== null ? (float) $r['total_revenue'] - (float) $r['total_cogs'] : null;
}
unset($r);

$byQty = $rows;
usort($byQty, static fn ($a, $b) => $b['total_qty'] <=> $a['total_qty']);
$byQty = array_slice($byQty, 0, 10);

$byRevenue = $rows;
usort($byRevenue, static fn ($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);
$byRevenue = array_slice($byRevenue, 0, 10);

$byGrossProfit = array_filter($rows, static fn ($r) => $r['unknown_cost_lines'] == 0);
usort($byGrossProfit, static fn ($a, $b) => $b['gross_profit'] <=> $a['gross_profit']);
$byGrossProfit = array_slice($byGrossProfit, 0, 10);

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <?php require __DIR__ . '/../includes/date_range_filter.php'; ?>
</div>

<div class="card">
  <strong>Paling Laku (berdasarkan qty satuan dasar)</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Produk</th><th class="text-right">Qty Terjual</th><th class="text-right">Omzet</th></tr></thead>
    <tbody>
    <?php foreach ($byQty as $r): ?>
      <tr><td><?= e($r['code']) ?> — <?= e($r['name']) ?></td><td class="text-right"><?= (int) $r['total_qty'] ?></td><td class="text-right"><?= rupiah($r['total_revenue']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$byQty): ?><tr><td colspan="3" class="empty-state">Tidak ada data.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <strong>Omzet Tertinggi</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Produk</th><th class="text-right">Omzet</th><th class="text-right">Qty Terjual</th></tr></thead>
    <tbody>
    <?php foreach ($byRevenue as $r): ?>
      <tr><td><?= e($r['code']) ?> — <?= e($r['name']) ?></td><td class="text-right"><?= rupiah($r['total_revenue']) ?></td><td class="text-right"><?= (int) $r['total_qty'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$byRevenue): ?><tr><td colspan="3" class="empty-state">Tidak ada data.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <strong>Gross Profit Tertinggi</strong>
  <p class="form-hint">Produk dengan biaya modal belum diketahui di sebagian transaksinya dikecualikan dari daftar ini supaya tidak menyesatkan.</p>
  <table style="margin-top:10px">
    <thead><tr><th>Produk</th><th class="text-right">Gross Profit</th><th class="text-right">Omzet</th></tr></thead>
    <tbody>
    <?php foreach ($byGrossProfit as $r): ?>
      <tr><td><?= e($r['code']) ?> — <?= e($r['name']) ?></td><td class="text-right"><?= rupiah($r['gross_profit']) ?></td><td class="text-right"><?= rupiah($r['total_revenue']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$byGrossProfit): ?><tr><td colspan="3" class="empty-state">Tidak ada data.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
