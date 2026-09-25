-- title: Tidak ada stok negatif
-- rule: BR-014: stok tidak boleh minus, baik saldo produk maupun saldo di ledger
-- severity: critical
SELECT 'products' AS sumber, id, current_stock_base AS saldo FROM products WHERE current_stock_base < 0
UNION ALL
SELECT 'stock_movements', id, balance_after_base FROM stock_movements WHERE balance_after_base < 0;
