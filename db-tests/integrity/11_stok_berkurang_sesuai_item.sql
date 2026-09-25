-- title: Stok berkurang tepat sebanyak barang yang dijual
-- rule: per penjualan dan produk: SUM(ledger 'sale') = -SUM(sale_items.qty_base)
-- severity: critical
SELECT i.sale_id, i.product_id, i.qty_terjual, COALESCE(m.qty_ledger, 0) AS qty_ledger
FROM (SELECT sale_id, product_id, SUM(qty_base) AS qty_terjual FROM sale_items GROUP BY sale_id, product_id) i
LEFT JOIN (SELECT reference_id AS sale_id, product_id, SUM(qty_delta_base) AS qty_ledger
             FROM stock_movements WHERE movement_type = 'sale' AND reference_type = 'sale'
            GROUP BY reference_id, product_id) m
       ON m.sale_id = i.sale_id AND m.product_id = i.product_id
WHERE COALESCE(m.qty_ledger, 0) <> -i.qty_terjual;
