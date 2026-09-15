<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);

if (!is_post()) {
    redirect('/products/index.php');
}

verify_csrf();

$id = (int) post('product_id');
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    flash_set('error', 'Produk tidak ditemukan.');
    redirect('/products/index.php');
}

$usageStmt = $pdo->prepare(
    'SELECT
        (SELECT COUNT(*) FROM sale_items WHERE product_id = ?) AS sale_count,
        (SELECT COUNT(*) FROM purchase_items WHERE product_id = ?) AS purchase_count,
        (SELECT COUNT(*) FROM stock_movements WHERE product_id = ?) AS movement_count'
);
$usageStmt->execute([$id, $id, $id]);
$usage = $usageStmt->fetch();

$isUsed = (int) $usage['sale_count'] > 0 || (int) $usage['purchase_count'] > 0 || (int) $usage['movement_count'] > 0;

if ($isUsed) {
    flash_set('error',
        'Produk "' . $product['name'] . '" tidak bisa dihapus permanen karena sudah pernah muncul di transaksi '
        . '(penjualan, pembelian, atau pergerakan stok) — menghapusnya akan merusak riwayat dan laporan lama. '
        . 'Nonaktifkan saja produk ini lewat tombol "Edit" > centang "Aktif" dimatikan, supaya tidak muncul lagi di kasir tapi riwayatnya tetap utuh.'
    );
    redirect('/products/index.php');
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM product_units WHERE product_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);

    log_audit($pdo, 'product_deleted', 'product', $id, [
        'code' => $product['code'],
        'name' => $product['name'],
        'base_unit_name' => $product['base_unit_name'],
    ], null);

    $pdo->commit();
    flash_set('success', 'Produk "' . $product['name'] . '" berhasil dihapus.');
} catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('error', 'Gagal menghapus produk: ' . $e->getMessage());
}

redirect('/products/index.php');
