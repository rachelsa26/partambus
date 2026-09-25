<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Tambah Supplier';

$errors = [];
$form = ['name' => '', 'contact_person' => '', 'phone' => '', 'note' => ''];
$returnTo = safe_return_path(trim((string) ($_GET['return_to'] ?? '')), '');

if (is_post()) {
    verify_csrf();
    $form['name'] = post('name');
    $form['contact_person'] = post('contact_person');
    $form['phone'] = post('phone');
    $form['note'] = post('note');
    $returnTo = safe_return_path(post('return_to'), '');

    if ($form['name'] === '') {
        $errors[] = 'Nama supplier wajib diisi.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (name, contact_person, phone, note, active) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $form['name'],
            $form['contact_person'] !== '' ? $form['contact_person'] : null,
            $form['phone'] !== '' ? $form['phone'] : null,
            $form['note'] !== '' ? $form['note'] : null,
        ]);
        $newId = (int) $pdo->lastInsertId();

        log_audit($pdo, 'supplier_created', 'supplier', $newId, null, $form);

        flash_set('success', 'Supplier "' . $form['name'] . '" berhasil dibuat.');
        redirect($returnTo !== '' ? $returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'supplier_id=' . $newId : '/suppliers/index.php');
    }
}

$backUrl = APP_BASE_PATH . ($returnTo !== '' ? $returnTo : '/suppliers/index.php');
$backLabel = $returnTo !== '' ? 'Kembali' : 'Kembali ke Daftar Supplier';

require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:480px">
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
  <div class="form-group">
    <label for="name">Nama Supplier</label>
    <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" required autofocus>
  </div>
  <div class="form-group">
    <label for="contact_person">Kontak Person (opsional)</label>
    <input type="text" id="contact_person" name="contact_person" value="<?= e($form['contact_person']) ?>">
  </div>
  <div class="form-group">
    <label for="phone">Telepon (opsional)</label>
    <input type="text" id="phone" name="phone" value="<?= e($form['phone']) ?>">
  </div>
  <div class="form-group">
    <label for="note">Catatan (opsional)</label>
    <textarea id="note" name="note" rows="3"><?= e($form['note']) ?></textarea>
  </div>
  <div class="btn-row">
    <button type="submit" class="btn">Simpan</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/suppliers/index.php">Batal</a>
  </div>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
