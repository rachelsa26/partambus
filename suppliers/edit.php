<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);
$pageTitle = 'Edit Supplier';
$backUrl = APP_BASE_PATH . '/suppliers/index.php';
$backLabel = 'Kembali ke Daftar Supplier';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
$stmt->execute([$id]);
$supplier = $stmt->fetch();

if (!$supplier) {
    flash_set('error', 'Supplier tidak ditemukan.');
    redirect('/suppliers/index.php');
}

$errors = [];

if (is_post()) {
    verify_csrf();
    $name = post('name');
    $contactPerson = post('contact_person');
    $phone = post('phone');
    $note = post('note');
    $active = isset($_POST['active']) ? 1 : 0;

    if ($name === '') {
        $errors[] = 'Nama supplier wajib diisi.';
    }

    if (!$errors) {
        $before = ['name' => $supplier['name'], 'active' => (int) $supplier['active']];
        $stmt = $pdo->prepare('UPDATE suppliers SET name = ?, contact_person = ?, phone = ?, note = ?, active = ? WHERE id = ?');
        $stmt->execute([
            $name,
            $contactPerson !== '' ? $contactPerson : null,
            $phone !== '' ? $phone : null,
            $note !== '' ? $note : null,
            $active,
            $id,
        ]);

        $afterForAudit = ['name' => $name, 'active' => $active];
        if (audit_has_real_change($before, $afterForAudit)) {
            log_audit($pdo, 'supplier_updated', 'supplier', $id, $before, $afterForAudit);
        }

        flash_set('success', 'Supplier berhasil diperbarui.');
        redirect('/suppliers/index.php');
    }

    $supplier = array_merge($supplier, compact('name', 'contactPerson', 'phone', 'note', 'active'));
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:480px">
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-group">
    <label for="name">Nama Supplier</label>
    <input type="text" id="name" name="name" value="<?= e($supplier['name']) ?>" required>
  </div>
  <div class="form-group">
    <label for="contact_person">Kontak Person</label>
    <input type="text" id="contact_person" name="contact_person" value="<?= e((string) $supplier['contact_person']) ?>">
  </div>
  <div class="form-group">
    <label for="phone">Telepon</label>
    <input type="text" id="phone" name="phone" value="<?= e((string) $supplier['phone']) ?>">
  </div>
  <div class="form-group">
    <label for="note">Catatan</label>
    <textarea id="note" name="note" rows="3"><?= e((string) $supplier['note']) ?></textarea>
  </div>
  <div class="form-group">
    <label class="checkbox-inline"><input type="checkbox" name="active" value="1" <?= (int) $supplier['active'] === 1 ? 'checked' : '' ?>> Supplier aktif</label>
  </div>
  <div class="btn-row">
    <button type="submit" class="btn">Simpan</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/suppliers/index.php">Kembali</a>
  </div>
</form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
