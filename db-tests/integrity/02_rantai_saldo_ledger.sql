-- title: Saldo berjalan di ledger stok tidak terputus
-- rule: balance_after_base = balance_after_base baris sebelumnya + qty_delta_base (per produk, urut id)
-- severity: critical
SELECT id, product_id, qty_delta_base, balance_after_base, saldo_sebelumnya
FROM (
  SELECT sm.*, COALESCE(LAG(balance_after_base) OVER (PARTITION BY product_id ORDER BY id), 0) AS saldo_sebelumnya
  FROM stock_movements sm
) t
WHERE balance_after_base <> saldo_sebelumnya + qty_delta_base;
