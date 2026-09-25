-- title: Penjualan tunai tercatat di sesi kas, non-tunai tidak
-- rule: OQ-C05: hanya penjualan tunai yang wajib punya cash_session_id
-- severity: normal
SELECT s.id, s.sale_number, p.method, s.cash_session_id
FROM sales s
JOIN payments p ON p.sale_id = s.id
WHERE (p.method = 'cash' AND s.cash_session_id IS NULL)
   OR (p.method <> 'cash' AND s.cash_session_id IS NOT NULL);
