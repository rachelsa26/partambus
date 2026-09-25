-- title: Setiap penjualan punya tepat satu pembayaran sebesar totalnya
-- rule: payments.amount = sales.total, satu baris per penjualan
-- severity: critical
SELECT s.id, s.sale_number, s.total, COUNT(p.id) AS jumlah_pembayaran, SUM(p.amount) AS total_bayar
FROM sales s
LEFT JOIN payments p ON p.sale_id = s.id
GROUP BY s.id, s.sale_number, s.total
HAVING jumlah_pembayaran <> 1 OR total_bayar <> s.total;
