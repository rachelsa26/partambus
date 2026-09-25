-- title: Setiap penjualan punya minimal satu item
-- rule: transaksi tanpa barang tidak boleh tersimpan
-- severity: normal
SELECT s.id, s.sale_number
FROM sales s
LEFT JOIN sale_items si ON si.sale_id = s.id
WHERE si.id IS NULL;
