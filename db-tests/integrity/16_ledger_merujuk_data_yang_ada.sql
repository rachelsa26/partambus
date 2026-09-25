-- title: Ledger stok dan kas tidak merujuk transaksi yang tidak ada
-- rule: reference_id bukan foreign key, jadi rujukannya dicek manual (orphan check)
-- severity: normal
SELECT 'stock_movements' AS tabel, sm.id, sm.reference_type, sm.reference_id
FROM stock_movements sm
LEFT JOIN sales s ON sm.reference_type = 'sale' AND s.id = sm.reference_id
WHERE sm.reference_type = 'sale' AND s.id IS NULL
UNION ALL
SELECT 'cash_movements', cm.id, cm.reference_type, cm.reference_id
FROM cash_movements cm
LEFT JOIN sales s ON cm.reference_type = 'sale' AND s.id = cm.reference_id
WHERE cm.reference_type = 'sale' AND s.id IS NULL;
