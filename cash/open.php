<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_login();
$pageTitle = 'Buka Sesi Kas';
$backUrl = APP_BASE_PATH . '/cash/index.php';
$backLabel = 'Kembali ke Cash Session';

if (get_active_cash_session($pdo)) {
    flash_set('error', 'Sudah ada sesi kas aktif.');
    redirect('/cash/index.php');
}

$errors = [];

if (is_post()) {
    verify_csrf();
    $openingCash = post('opening_cash');

    if (!is_numeric($openingCash) || (float) $openingCash < 0) {
        $errors[] = 'Kas awal harus angka >= 0.';
    }

    if (!$errors) {
        if (get_active_cash_session($pdo)) {
            $errors[] = 'Sudah ada sesi kas aktif.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO cash_sessions (opened_by, opening_cash, status) VALUES (?, ?, "open")');
            $stmt->execute([$actor['id'], (float) $openingCash]);
            $sessionId = (int) $pdo->lastInsertId();

            log_audit($pdo, 'cash_session_opened', 'cash_session', $sessionId, null, ['opening_cash' => (float) $openingCash]);

            flash_set('success', 'Sesi kas dibuka dengan kas awal ' . rupiah($openingCash) . '.');
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

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-group">
    <label for="opening_cash">Kas Awal di Laci</label>
    <input type="number" id="opening_cash" name="opening_cash" min="0" step="1" required autofocus>
  </div>
  <div class="btn-row">
    <button type="submit" class="btn">Buka Sesi</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/cash/index.php">Batal</a>
  </div>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
