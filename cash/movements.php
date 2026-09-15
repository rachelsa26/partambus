<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_role(['owner']);
$pageTitle = 'Kas Masuk / Keluar Manual';
$backUrl = APP_BASE_PATH . '/cash/index.php';
$backLabel = 'Kembali ke Cash Session';

$active = get_active_cash_session($pdo);
if (!$active) {
    flash_set('error', 'Tidak ada sesi kas aktif.');
    redirect('/cash/index.php');
}

$errors = [];

if (is_post()) {
    verify_csrf();
    $direction = post('direction');
    $amount = post('amount');
    $reason = post('reason');

    if (!in_array($direction, ['in', 'out'], true)) {
        $errors[] = 'Arah tidak valid.';
    }
    if (!is_numeric($amount) || (float) $amount <= 0) {
        $errors[] = 'Jumlah harus angka > 0.';
    }
    if ($reason === '') {
        $errors[] = 'Alasan wajib diisi.';
    }

    if (!$errors) {
        $signedAmount = $direction === 'in' ? (float) $amount : -(float) $amount;
        record_cash_movement($pdo, (int) $active['id'], $direction === 'in' ? 'cash_in' : 'cash_out', $signedAmount, null, null, $actor['id'], $reason);

        log_audit($pdo, 'cash_manual_movement', 'cash_session', (int) $active['id'], null, [
            'direction' => $direction, 'amount' => (float) $amount,
        ], $reason);

        flash_set('success', 'Kas ' . ($direction === 'in' ? 'masuk' : 'keluar') . ' tercatat.');
        redirect('/cash/index.php');
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:420px">
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-group">
    <label>Arah</label>
    <div class="form-row">
      <label class="checkbox-inline"><input type="radio" name="direction" value="in" checked> Kas Masuk</label>
      <label class="checkbox-inline"><input type="radio" name="direction" value="out"> Kas Keluar</label>
    </div>
  </div>
  <div class="form-group">
    <label for="amount">Jumlah</label>
    <input type="number" id="amount" name="amount" min="1" step="1" required>
  </div>
  <div class="form-group">
    <label for="reason">Alasan (wajib)</label>
    <textarea id="reason" name="reason" rows="3" required></textarea>
  </div>
  <div class="btn-row">
    <button type="submit" class="btn">Simpan</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/cash/index.php">Batal</a>
  </div>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
