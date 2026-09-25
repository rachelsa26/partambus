-- title: Kode produk ganda (beda huruf besar/kecil) ditolak database
-- rule: UNIQUE uq_products_code_normalized
-- severity: critical
-- expect: reject
SET @kode := (SELECT code_normalized FROM products ORDER BY id LIMIT 1);
INSERT INTO products (code, code_normalized, name, base_unit_name)
VALUES (LOWER(@kode), @kode, 'Produk kode ganda', 'PCS');
