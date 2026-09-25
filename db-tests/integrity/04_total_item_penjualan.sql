-- title: Total per item penjualan dihitung benar
-- rule: line_total = unit_price x qty_unit - discount_amount, dan qty_base = qty_unit x faktor konversi
-- severity: critical
SELECT id, sale_id, qty_unit, conversion_factor_snapshot, qty_base, unit_price, discount_amount, line_total
FROM sale_items
WHERE line_total <> unit_price * qty_unit - discount_amount
   OR qty_base <> qty_unit * conversion_factor_snapshot;
