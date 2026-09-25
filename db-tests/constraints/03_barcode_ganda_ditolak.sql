-- title: Barcode ganda ditolak database
-- rule: UNIQUE uq_products_barcode
-- severity: normal
-- expect: reject
INSERT INTO products (code, code_normalized, barcode, name, base_unit_name)
VALUES ('DB-TEST-BC1', 'DB-TEST-BC1', 'DB-TEST-BARCODE', 'Produk barcode 1', 'PCS');
INSERT INTO products (code, code_normalized, barcode, name, base_unit_name)
VALUES ('DB-TEST-BC2', 'DB-TEST-BC2', 'DB-TEST-BARCODE', 'Produk barcode 2', 'PCS');
