-- title: Penjualan void mengembalikan seluruh stok dan tercatat lengkap
-- rule: void: void_reason, voided_by, voided_at terisi dan ledger sale + sale_void per produk = 0
-- severity: critical
SELECT s.id, s.sale_number, s.void_reason, s.voided_by, s.voided_at, m.product_id, m.selisih
FROM sales s
LEFT JOIN (SELECT reference_id AS sale_id, product_id, SUM(qty_delta_base) AS selisih
             FROM stock_movements
            WHERE reference_type = 'sale' AND movement_type IN ('sale', 'sale_void')
            GROUP BY reference_id, product_id) m ON m.sale_id = s.id
WHERE s.status = 'void'
  AND (s.void_reason IS NULL OR s.voided_by IS NULL OR s.voided_at IS NULL OR m.selisih <> 0);
