<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_role(['owner']);
$pageTitle = 'Backup & Restore';

$errors = [];

if (is_post()) {
    verify_csrf();
    $action = post('form_action');

    if ($action === 'create_backup') {
        try {
            $result = create_backup($pdo, 'manual');
            $insert = $pdo->prepare(
                'INSERT INTO backup_history (file_name, created_by, status, note) VALUES (?, ?, "success", ?)'
            );
            $insert->execute([$result['filename'], $actor['id'], $result['table_count'] . ' tabel']);

            log_audit($pdo, 'backup_created', 'backup', (int) $pdo->lastInsertId(), null, [
                'file_name' => $result['filename'], 'size' => $result['size'],
            ]);

            flash_set('success', 'Backup berhasil dibuat: ' . $result['filename'] . ' (' . number_format($result['size'] / 1024, 0) . ' KB).');
        } catch (Throwable $e) {
            $insert = $pdo->prepare(
                'INSERT INTO backup_history (file_name, created_by, status, note) VALUES (?, ?, "failed", ?)'
            );
            $insert->execute(['-', $actor['id'], $e->getMessage()]);
            flash_set('error', 'Backup gagal: ' . $e->getMessage());
        }
        redirect('/backup/index.php');
    }
}

$lastSuccess = $pdo->query(
    'SELECT created_at FROM backup_history WHERE status = "success" ORDER BY id DESC LIMIT 1'
)->fetchColumn();

$reminderHours = (int) get_setting($pdo, 'backup_reminder_hours', '24');
$needsReminder = true;
if ($lastSuccess) {
    $hoursSince = (time() - strtotime($lastSuccess)) / 3600;
    $needsReminder = $hoursSince > $reminderHours;
}

$history = $pdo->query(
    'SELECT bh.*, u.full_name AS created_by_name FROM backup_history bh
     JOIN users u ON u.id = bh.created_by ORDER BY bh.id DESC LIMIT 30'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($needsReminder): ?>
  <div class="flash flash-warning">
    <?= $lastSuccess ? 'Backup terakhir sudah lebih dari ' . $reminderHours . ' jam yang lalu (' . e($lastSuccess) . ').' : 'Belum pernah ada backup berhasil.' ?>
    Sebaiknya buat backup sekarang, terutama sebelum menutup toko atau mematikan komputer.
  </div>
<?php else: ?>
  <div class="flash flash-success">Backup terakhir: <?= e($lastSuccess) ?>.</div>
<?php endif; ?>

<div class="card" style="max-width:480px">
  <p>Backup menyimpan seluruh data (produk, transaksi, stok, pengguna, dst) ke satu file di komputer ini. <strong>Setelah dibuat, segera salin file-nya ke Google Drive/flashdisk</strong> — backup yang cuma tersimpan di komputer yang sama tidak melindungi dari kerusakan komputer itu sendiri.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="create_backup">
    <button type="submit" class="btn">Buat Backup Sekarang</button>
  </form>
</div>

<div class="btn-row" style="margin-bottom:16px">
  <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/backup/restore.php">Restore dari Backup</a>
</div>

<div class="card">
  <strong>Riwayat Backup</strong>
  <table style="margin-top:10px">
    <thead><tr><th>File</th><th>Dibuat Oleh</th><th>Waktu</th><th>Status</th><th>Catatan</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
      <tr>
        <td><?= e($h['file_name']) ?></td>
        <td><?= e($h['created_by_name']) ?></td>
        <td class="text-muted"><?= e(fmt_date($h['created_at'])) ?></td>
        <td><span class="badge badge-<?= $h['status'] === 'success' ? 'active' : 'inactive' ?>"><?= $h['status'] === 'success' ? 'Berhasil' : 'Gagal' ?></span></td>
        <td class="text-muted"><?= e((string) $h['note']) ?></td>
        <td>
          <?php if ($h['status'] === 'success'): ?>
            <a href="<?= APP_BASE_PATH ?>/backup/download.php?id=<?= (int) $h['id'] ?>">Unduh</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?>
      <tr><td colspan="6" class="empty-state">Belum ada backup.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
