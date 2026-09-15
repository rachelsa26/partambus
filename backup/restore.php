<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_role(['owner']);
$pageTitle = 'Restore Database';
$backUrl = APP_BASE_PATH . '/backup/index.php';
$backLabel = 'Kembali ke Backup & Restore';

$backups = $pdo->query(
    'SELECT bh.id, bh.file_name, bh.created_at, u.full_name AS created_by_name
     FROM backup_history bh JOIN users u ON u.id = bh.created_by
     WHERE bh.status = "success" ORDER BY bh.id DESC LIMIT 30'
)->fetchAll();

$errors = [];

if (is_post()) {
    verify_csrf();
    $existingId = (int) post('existing_backup_id');
    $confirmPhrase = post('confirm_phrase');

    if ($confirmPhrase !== 'PULIHKAN') {
        $errors[] = 'Ketik "PULIHKAN" persis (huruf besar semua) untuk konfirmasi. Ini tindakan merusak yang mengganti seluruh data saat ini.';
    }

    $sourcePath = null;
    $sourceLabel = null;

    if (!$errors) {
        if (!empty($_FILES['backup_file']['tmp_name']) && is_uploaded_file($_FILES['backup_file']['tmp_name'])) {
            $uploadName = 'uploaded_' . date('Ymd_His') . '.sql';
            $uploadPath = BACKUP_DIR . '/' . $uploadName;
            if (!is_dir(BACKUP_DIR)) {
                mkdir(BACKUP_DIR, 0755, true);
            }
            if (!move_uploaded_file($_FILES['backup_file']['tmp_name'], $uploadPath)) {
                $errors[] = 'Gagal menyimpan file yang diupload.';
            } else {
                $sourcePath = $uploadPath;
                $sourceLabel = 'upload: ' . basename((string) $_FILES['backup_file']['name']);
            }
        } elseif ($existingId > 0) {
            $stmt = $pdo->prepare('SELECT file_name FROM backup_history WHERE id = ? AND status = "success"');
            $stmt->execute([$existingId]);
            $fileName = $stmt->fetchColumn();
            if (!$fileName) {
                $errors[] = 'Backup yang dipilih tidak ditemukan.';
            } else {
                $sourcePath = BACKUP_DIR . '/' . $fileName;
                $sourceLabel = $fileName;
            }
        } else {
            $errors[] = 'Pilih salah satu backup yang ada, atau upload file backup.';
        }
    }

    if (!$errors && $sourcePath !== null) {
        try {
            validate_backup_file($sourcePath);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors && $sourcePath !== null) {
        $preRestoreFile = null;
        try {
            $pre = create_backup($pdo, 'pre_restore_auto');
            $preRestoreFile = $pre['filename'];
            $pdo->prepare('INSERT INTO backup_history (file_name, created_by, status, note) VALUES (?, ?, "success", "backup otomatis sebelum restore")')
                ->execute([$pre['filename'], $actor['id']]);
        } catch (Throwable $e) {
            $errors[] = 'Restore dibatalkan: gagal membuat backup pengaman sebelum restore (' . $e->getMessage() . '). Tidak ada data yang diubah.';
        }

        if (!$errors) {
            try {
                $statementCount = restore_backup($pdo, $sourcePath);

                try {
                    log_audit($pdo, 'database_restored', 'backup', null, null, [
                        'source' => $sourceLabel, 'pre_restore_backup' => $preRestoreFile, 'statements_executed' => $statementCount,
                    ]);
                } catch (Throwable) {
                    // best-effort only; do not let audit logging mask a successful restore
                }

                logout();
                flash_set('success', 'Database berhasil dipulihkan dari "' . $sourceLabel . '". Silakan login kembali.');
                redirect('/auth/login.php');
            } catch (Throwable $e) {
                logout();
                flash_set('error', 'Restore GAGAL di tengah proses: ' . $e->getMessage() . '. Backup pengaman tersimpan sebagai "' . $preRestoreFile . '" — hubungi teknisi untuk memulihkan dari file itu. Anda akan diminta login ulang.');
                redirect('/auth/login.php');
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:560px;border-color:var(--color-danger)">
  <strong class="text-danger">Peringatan: tindakan ini mengganti SELURUH data saat ini</strong>
  <p>Semua produk, transaksi, stok, dan pengguna yang dibuat setelah waktu backup yang dipilih akan <strong>hilang permanen</strong> dan digantikan isi backup tersebut. Sistem akan otomatis membuat backup pengaman dari kondisi saat ini sebelum mengganti apa pun, tapi tetap pastikan Anda benar-benar yakin.</p>
</div>

<div class="card" style="max-width:560px">
<form method="post" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="form-group">
    <label>Pilih Backup</label>
    <?php foreach ($backups as $b): ?>
      <label class="checkbox-inline" style="display:block">
        <input type="radio" name="existing_backup_id" value="<?= (int) $b['id'] ?>">
        <?= e($b['file_name']) ?> — <?= e(fmt_date($b['created_at'])) ?> (oleh <?= e($b['created_by_name']) ?>)
      </label>
    <?php endforeach; ?>
    <?php if (!$backups): ?>
      <p class="text-muted">Belum ada backup tersimpan di komputer ini.</p>
    <?php endif; ?>
  </div>
  <div class="form-group">
    <label for="backup_file">Atau upload file backup (.sql) dari luar</label>
    <input type="file" id="backup_file" name="backup_file" accept=".sql">
  </div>
  <div class="form-group">
    <label for="confirm_phrase">Ketik <code>PULIHKAN</code> untuk konfirmasi</label>
    <input type="text" id="confirm_phrase" name="confirm_phrase" required autocomplete="off">
  </div>
  <div class="btn-row">
    <button type="submit" class="btn btn-danger" data-confirm="Yakin? Data saat ini akan diganti dengan isi backup yang dipilih.">Pulihkan Database</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/backup/index.php">Batal</a>
  </div>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
