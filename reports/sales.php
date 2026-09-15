<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Laporan Penjualan & Gross Profit';
$backUrl = APP_BASE_PATH . '/reports/index.php';
$backLabel = 'Kembali ke Laporan';

$range = resolve_date_range((string) ($_GET['preset'] ?? 'today'), (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));

$salesStmt = $pdo->prepare(
    'SELECT COUNT(*) AS tx_count, COALESCE(SUM(total),0) AS revenue, COALESCE(AVG(total),0) AS avg_tx, COALESCE(SUM(discount_total),0) AS discount_total
     FROM sales WHERE status = "completed" AND created_at BETWEEN ? AND ?'
);
$salesStmt->execute([$range['from'], $range['to']]);
$salesSummary = $salesStmt->fetch();

$itemsStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(si.qty_base),0) AS total_qty, COALESCE(SUM(si.cogs_total),0) AS total_cogs,
            SUM(CASE WHEN si.cogs_total IS NULL THEN 1 ELSE 0 END) AS unknown_cost_lines
     FROM sale_items si JOIN sales s ON s.id = si.sale_id
     WHERE s.status = "completed" AND s.created_at BETWEEN ? AND ?'
);
$itemsStmt->execute([$range['from'], $range['to']]);
$itemsSummary = $itemsStmt->fetch();

$revenue = (float) $salesSummary['revenue'];
$cogs = (float) $itemsSummary['total_cogs'];
$grossProfit = $revenue - $cogs;
$margin = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0.0;

$paymentStmt = $pdo->prepare(
    'SELECT p.method, COUNT(*) AS cnt, COALESCE(SUM(p.amount),0) AS total
     FROM payments p JOIN sales s ON s.id = p.sale_id
     WHERE s.status = "completed" AND s.created_at BETWEEN ? AND ?
     GROUP BY p.method ORDER BY total DESC'
);
$paymentStmt->execute([$range['from'], $range['to']]);
$byMethod = $paymentStmt->fetchAll();

$dailyStmt = $pdo->prepare(
    'SELECT DATE(created_at) AS d, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS revenue
     FROM sales WHERE status = "completed" AND created_at BETWEEN ? AND ?
     GROUP BY DATE(created_at) ORDER BY d DESC'
);
$dailyStmt->execute([$range['from'], $range['to']]);
$daily = $dailyStmt->fetchAll();

$voidStmt = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE status = "void" AND created_at BETWEEN ? AND ?');
$voidStmt->execute([$range['from'], $range['to']]);
$voidCount = (int) $voidStmt->fetchColumn();

$methodLabels = PAYMENT_METHOD_LABELS;

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <?php require __DIR__ . '/../includes/date_range_filter.php'; ?>
</div>

<div class="card-grid">
  <div class="card">
    <div class="text-muted">Omzet (Revenue)</div>
    <div style="font-size:22px;font-weight:700"><?= rupiah($revenue) ?></div>
  </div>
  <div class="card">
    <div class="text-muted">Jumlah Transaksi</div>
    <div style="font-size:22px;font-weight:700"><?= (int) $salesSummary['tx_count'] ?></div>
  </div>
  <div class="card">
    <div class="text-muted">Rata-rata per Transaksi</div>
    <div style="font-size:22px;font-weight:700"><?= rupiah($salesSummary['avg_tx']) ?></div>
  </div>
  <div class="card">
    <div class="text-muted">Total HPP</div>
    <div style="font-size:22px;font-weight:700"><?= rupiah($cogs) ?></div>
    <?php if ($itemsSummary['unknown_cost_lines'] > 0): ?>
      <p class="form-hint">*<?= (int) $itemsSummary['unknown_cost_lines'] ?> baris item biaya modalnya belum diketahui, HPP mungkin lebih rendah dari sebenarnya.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="text-muted">Gross Profit</div>
    <div style="font-size:22px;font-weight:700"><?= rupiah($grossProfit) ?></div>
    <p class="form-hint">Margin <?= number_format($margin, 1) ?>%</p>
  </div>
  <div class="card">
    <div class="text-muted">Total Diskon</div>
    <div style="font-size:22px;font-weight:700"><?= rupiah($salesSummary['discount_total']) ?></div>
  </div>
</div>

<?php if ($voidCount > 0): ?>
  <div class="flash flash-warning"><?= $voidCount ?> transaksi di periode ini berstatus VOID dan tidak dihitung dalam omzet/gross profit di atas.</div>
<?php endif; ?>

<div class="card">
  <strong>Berdasarkan Metode Pembayaran</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Metode</th><th class="text-right">Jumlah Transaksi</th><th class="text-right">Total</th></tr></thead>
    <tbody>
    <?php foreach ($byMethod as $m): ?>
      <tr>
        <td><?= e($methodLabels[$m['method']] ?? $m['method']) ?></td>
        <td class="text-right"><?= (int) $m['cnt'] ?></td>
        <td class="text-right"><?= rupiah($m['total']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$byMethod): ?>
      <tr><td colspan="3" class="empty-state">Tidak ada transaksi di periode ini.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <strong>Per Hari</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Tanggal</th><th class="text-right">Jumlah Transaksi</th><th class="text-right">Omzet</th></tr></thead>
    <tbody>
    <?php foreach ($daily as $d): ?>
      <tr>
        <td><?= e($d['d']) ?></td>
        <td class="text-right"><?= (int) $d['cnt'] ?></td>
        <td class="text-right"><?= rupiah($d['revenue']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$daily): ?>
      <tr><td colspan="3" class="empty-state">Tidak ada transaksi di periode ini.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
