<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_login();
$pageTitle = 'Tutup Sesi Kas';
$backUrl = APP_BASE_PATH . '/cash/index.php';
$backLabel = 'Kembali ke Cash Session';

$active = get_active_cash_session($pdo);
if (!$active) {
    flash_set('error', 'Tidak ada sesi kas aktif.');
    redirect('/cash/index.php');
}

$expected = calculate_expected_cash($pdo, (int) $active['id'], (float) $active['opening_cash']);
$errors = [];

if (is_post()) {
    verify_csrf();
    $actualCash = post('actual_cash');
    $note = post('note');

    if (!is_numeric($actualCash) || (float) $actualCash < 0) {
        $errors[] = 'Kas aktual harus angka >= 0.';
    }

    if (!$errors) {
        $difference = round((float) $actualCash - $expected, 2);
        if ($difference !== 0.0 && $note === '') {
            $errors[] = 'Alasan wajib diisi karena ada selisih kas (' . rupiah($difference) . ').';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'UPDATE cash_sessions SET status = "closed", closed_by = ?, expected_cash = ?, actual_cash = ?, difference = ?, note = ?, closed_at = NOW()
             WHERE id = ? AND status = "open"'
        );
        $stmt->execute([$actor['id'], $expected, (float) $actualCash, $difference, $note !== '' ? $note : null, $active['id']]);

        if ($stmt->rowCount() !== 1) {
            $errors[] = 'Sesi ini sudah ditutup sebelumnya.';
        } else {
            log_audit($pdo, 'cash_session_closed', 'cash_session', (int) $active['id'], null, [
                'expected_cash' => $expected, 'actual_cash' => (float) $actualCash, 'difference' => $difference,
            ]);

            flash_set('success', 'Sesi kas ditutup. Selisih: ' . rupiah($difference) . '.');
            redirect('/cash/index.php');
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:420px">
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<p>Kas awal: <?= rupiah($active['opening_cash']) ?></p>
<p><strong>Kas diharapkan: <?= rupiah($expected) ?></strong></p>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-group">
    <label for="actual_cash">Kas Aktual (hasil hitung fisik)</label>
    <input type="number" id="actual_cash" name="actual_cash" min="0" step="1" required autofocus>
  </div>
  <div class="form-group">
    <label for="note">Catatan (wajib jika ada selisih)</label>
    <textarea id="note" name="note" rows="3"></textarea>
  </div>
  <div class="btn-row">
    <button type="submit" class="btn" data-confirm="Tutup sesi kas ini? Setelah ditutup tidak bisa diubah lagi.">Tutup Sesi</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/cash/index.php">Batal</a>
  </div>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
