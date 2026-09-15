<?php
/**
 * @var PDO $pdo
 * Single-select product picker: click a row (or its "Pilih" link) to
 * navigate to `?product_id=<id>` in the including page. Used by pages that
 * need to pick exactly one product to act on next (record initial stock, an
 * adjustment, add a purchase line) — not used by POS, which has its own
 * per-sellable-unit search table for adding straight to the cart.
 */
$pickerSearch = trim((string) ($_GET['q'] ?? ''));

if ($pickerSearch !== '') {
    $stmt = $pdo->prepare(
        'SELECT id, code, name, base_unit_name, current_stock_base FROM products
         WHERE active = 1 AND (code LIKE ? OR barcode LIKE ? OR name LIKE ?) ORDER BY name LIMIT 20'
    );
    $like = '%' . $pickerSearch . '%';
    $stmt->execute([$like, $like, $like]);
} else {
    $stmt = $pdo->query('SELECT id, code, name, base_unit_name, current_stock_base FROM products WHERE active = 1 ORDER BY name LIMIT 20');
}
$pickerProducts = $stmt->fetchAll();
?>
<div class="card">
  <form method="get" class="pos-search-form">
    <input type="search" name="q" value="<?= e($pickerSearch) ?>" id="picker-search-input" class="pos-search-input" placeholder="Cari kode atau nama produk..." autocomplete="off">
    <button type="submit" class="btn btn-secondary">Cari</button>
  </form>
  <table>
    <thead>
      <tr><th>Kode</th><th>Nama</th><th>Satuan</th><th class="text-right">Stok</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($pickerProducts as $p): ?>
      <tr class="clickable-row" data-href="?product_id=<?= (int) $p['id'] ?>" tabindex="0">
        <td><?= e($p['code']) ?></td>
        <td><?= e($p['name']) ?></td>
        <td><?= e($p['base_unit_name']) ?></td>
        <td class="text-right"><?= (int) $p['current_stock_base'] ?></td>
        <td><a class="btn btn-small" href="?product_id=<?= (int) $p['id'] ?>">Pilih</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$pickerProducts): ?>
      <tr><td colspan="5" class="empty-state">Produk aktif tidak ditemukan.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
