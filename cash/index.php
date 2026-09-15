<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_login();
$pageTitle = 'Cash Session';

$active = get_active_cash_session($pdo);
$expected = null;
$movements = [];

if ($active) {
    $expected = calculate_expected_cash($pdo, (int) $active['id'], (float) $active['opening_cash']);
    $mStmt = $pdo->prepare(
        'SELECT cm.*, u.full_name AS actor_name FROM cash_movements cm JOIN users u ON u.id = cm.actor_user_id
         WHERE cm.cash_session_id = ? ORDER BY cm.id DESC'
    );
    $mStmt->execute([$active['id']]);
    $movements = $mStmt->fetchAll();
}

$historyStmt = $pdo->prepare(
    'SELECT cs.*, ob.full_name AS opened_by_name, cb.full_name AS closed_by_name
     FROM cash_sessions cs
     JOIN users ob ON ob.id = cs.opened_by
     LEFT JOIN users cb ON cb.id = cs.closed_by
     WHERE cs.status = "closed" ORDER BY cs.closed_at DESC LIMIT 20'
);
$historyStmt->execute();
$history = $historyStmt->fetchAll();

$typeLabels = [
    'cash_sale' => 'Penjualan Tunai', 'cash_sale_void' => 'Void Penjualan Tunai',
    'purchase_payment' => 'Pembayaran Pembelian', 'cash_in' => 'Kas Masuk', 'cash_out' => 'Kas Keluar',
];

require __DIR__ . '/../includes/header.php';
?>

<?php if ($active): ?>
<div class="card">
  <strong>Sesi Aktif</strong> — dibuka oleh <?= e($active['opened_by_name'] ?? '') ?> pada <?= e(fmt_date($active['opened_at'])) ?>
  <table style="margin-top:10px">
    <tbody>
      <tr><td>Kas Awal</td><td class="text-right"><?= rupiah($active['opening_cash']) ?></td></tr>
      <tr><td><strong>Kas Diharapkan (saat ini)</strong></td><td class="text-right"><strong><?= rupiah($expected) ?></strong></td></tr>
    </tbody>
  </table>
</div>

<div class="btn-row" style="margin-bottom:16px">
  <a class="btn" href="<?= APP_BASE_PATH ?>/cash/close.php">Tutup Sesi</a>
  <?php if (is_owner()): ?>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/cash/movements.php">Kas Masuk/Keluar Manual</a>
  <?php endif; ?>
</div>

<div class="card">
  <strong>Pergerakan Kas Sesi Ini</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Waktu</th><th>Tipe</th><th class="text-right">Jumlah</th><th>Referensi</th><th>Aktor</th><th>Alasan</th></tr></thead>
    <tbody>
    <?php foreach ($movements as $m): ?>
      <tr>
        <td class="text-muted"><?= e(fmt_date($m['created_at'])) ?></td>
        <td><?= e($typeLabels[$m['movement_type']] ?? $m['movement_type']) ?></td>
        <td class="text-right <?= $m['amount'] < 0 ? 'text-danger' : '' ?>"><?= $m['amount'] >= 0 ? '+' : '' ?><?= rupiah($m['amount']) ?></td>
        <td class="text-muted"><?= $m['reference_type'] ? e($m['reference_type']) . ' #' . (int) $m['reference_id'] : '-' ?></td>
        <td><?= e($m['actor_name']) ?></td>
        <td class="text-muted"><?= e((string) $m['reason']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$movements): ?>
      <tr><td colspan="6" class="empty-state">Belum ada pergerakan kas di sesi ini.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php else: ?>
<div class="card" style="max-width:480px">
  <p>Belum ada sesi kas aktif. Buka sesi sebelum menerima pembayaran tunai.</p>
  <a class="btn" href="<?= APP_BASE_PATH ?>/cash/open.php">Buka Sesi Kas</a>
</div>
<?php endif; ?>

<div class="card">
  <strong>Riwayat Sesi Ditutup</strong>
  <table style="margin-top:10px">
    <thead><tr><th>Dibuka</th><th>Ditutup</th><th class="text-right">Kas Awal</th><th class="text-right">Diharapkan</th><th class="text-right">Aktual</th><th class="text-right">Selisih</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
      <tr>
        <td class="text-muted"><?= e($h['opened_by_name']) ?><br><?= e(fmt_date($h['opened_at'])) ?></td>
        <td class="text-muted"><?= e($h['closed_by_name'] ?? '-') ?><br><?= e(fmt_date($h['closed_at'])) ?></td>
        <td class="text-right"><?= rupiah($h['opening_cash']) ?></td>
        <td class="text-right"><?= rupiah($h['expected_cash']) ?></td>
        <td class="text-right"><?= rupiah($h['actual_cash']) ?></td>
        <td class="text-right <?= (float) $h['difference'] !== 0.0 ? 'text-danger' : '' ?>"><?= rupiah($h['difference']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?>
      <tr><td colspan="6" class="empty-state">Belum ada riwayat sesi.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
