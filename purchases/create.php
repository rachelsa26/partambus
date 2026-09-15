<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$actor = require_role(['owner']);
$pageTitle = 'Draft Pembelian Baru';
$backUrl = APP_BASE_PATH . '/purchases/index.php';
$backLabel = 'Kembali ke Daftar Pembelian';

$suppliers = $pdo->query('SELECT id, name FROM suppliers WHERE active = 1 ORDER BY name')->fetchAll();
$preselectSupplierId = (int) ($_GET['supplier_id'] ?? 0);

$errors = [];

if (is_post()) {
    verify_csrf();
    $supplierId = (int) post('supplier_id');
    $notes = post('notes');

    $validSupplier = false;
    foreach ($suppliers as $s) {
        if ((int) $s['id'] === $supplierId) {
            $validSupplier = true;
            break;
        }
    }
    if (!$validSupplier) {
        $errors[] = 'Pilih supplier yang valid.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $prefix = get_setting($pdo, 'purchase_number_prefix', 'PU');
            $purchaseNumber = generate_daily_number($pdo, 'purchase_number_counters', $prefix);

            $stmt = $pdo->prepare(
                'INSERT INTO purchases (purchase_number, supplier_id, status, total_amount, notes, created_by)
                 VALUES (?, ?, "draft", 0, ?, ?)'
            );
            $stmt->execute([$purchaseNumber, $supplierId, $notes !== '' ? $notes : null, $actor['id']]);
            $purchaseId = (int) $pdo->lastInsertId();

            log_audit($pdo, 'purchase_draft_created', 'purchase', $purchaseId, null, [
                'purchase_number' => $purchaseNumber,
                'supplier_id' => $supplierId,
            ]);

            $pdo->commit();
            redirect('/purchases/edit.php?id=' . $purchaseId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Gagal membuat draft: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:480px">
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if (!$suppliers): ?>
  <p class="text-muted">Belum ada supplier aktif. Tambah supplier dulu sebelum membuat pembelian.</p>
  <a class="btn" href="<?= APP_BASE_PATH ?>/suppliers/create.php?return_to=<?= urlencode('/purchases/create.php') ?>">+ Tambah Supplier</a>
<?php else: ?>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-group">
    <label for="supplier_id">Supplier</label>
    <select id="supplier_id" name="supplier_id" required>
      <option value="">-- pilih supplier --</option>
      <?php foreach ($suppliers as $s): ?>
        <option value="<?= (int) $s['id'] ?>" <?= $preselectSupplierId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="form-hint"><a href="<?= APP_BASE_PATH ?>/suppliers/create.php?return_to=<?= urlencode('/purchases/create.php') ?>">+ Tambah supplier baru</a></p>
  </div>
  <div class="form-group">
    <label for="notes">Catatan (opsional)</label>
    <textarea id="notes" name="notes" rows="3"></textarea>
  </div>
  <div class="btn-row">
    <button type="submit" class="btn">Buat Draft</button>
    <a class="btn btn-secondary" href="<?= APP_BASE_PATH ?>/purchases/index.php">Batal</a>
  </div>
</form>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
