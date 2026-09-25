-- title: Setiap produk punya tepat satu satuan dasar dengan konversi 1
-- rule: BR-006: satuan dasar = 1; BR-007: satuan lain kelipatan bilangan bulat positif
-- severity: critical
SELECT p.id, p.code, SUM(pu.is_base = 1) AS jumlah_satuan_dasar,
       SUM(pu.is_base = 1 AND pu.conversion_factor <> 1) AS satuan_dasar_bukan_1
FROM products p
LEFT JOIN product_units pu ON pu.product_id = p.id
GROUP BY p.id, p.code
HAVING jumlah_satuan_dasar <> 1 OR satuan_dasar_bukan_1 > 0;
