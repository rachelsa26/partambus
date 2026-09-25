-- title: Setiap penjualan tunai menambah kas di sesinya sebesar total
-- rule: cash_movements 'cash_sale' = sales.total untuk sesi kas yang sama
-- severity: critical
SELECT s.id, s.sale_number, s.total, s.cash_session_id, cm.amount AS kas_masuk
FROM sales s
JOIN payments p ON p.sale_id = s.id AND p.method = 'cash'
LEFT JOIN cash_movements cm
       ON cm.reference_type = 'sale' AND cm.reference_id = s.id AND cm.movement_type = 'cash_sale'
WHERE cm.id IS NULL OR cm.amount <> s.total OR cm.cash_session_id <> s.cash_session_id;
