-- title: Subtotal, diskon, dan total penjualan cocok dengan item-itemnya
-- rule: sales.subtotal = SUM(harga x qty); discount_total = SUM(diskon item); total = subtotal - discount_total
-- severity: critical
SELECT s.id, s.sale_number, s.subtotal, s.discount_total, s.total,
       SUM(si.unit_price * si.qty_unit) AS subtotal_item,
       SUM(si.discount_amount) AS diskon_item
FROM sales s
JOIN sale_items si ON si.sale_id = s.id
GROUP BY s.id, s.sale_number, s.subtotal, s.discount_total, s.total
HAVING s.subtotal <> subtotal_item
    OR s.discount_total <> diskon_item
    OR s.total <> s.subtotal - s.discount_total;
