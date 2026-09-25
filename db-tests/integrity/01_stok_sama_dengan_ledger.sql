-- title: Stok produk sama dengan total ledger pergerakan stok
-- rule: BR-011: products.current_stock_base adalah saldo; stock_movements adalah buku besarnya
-- severity: critical
SELECT p.id, p.code, p.current_stock_base,
       COALESCE(SUM(sm.qty_delta_base), 0) AS total_ledger
FROM products p
LEFT JOIN stock_movements sm ON sm.product_id = p.id
GROUP BY p.id, p.code, p.current_stock_base
HAVING p.current_stock_base <> total_ledger;
