-- title: [KNOWN BUG] Database menerima jumlah item penjualan negatif
-- rule: sale_items.qty_unit tidak punya CHECK (qty_unit > 0); validasi hanya di PHP
-- severity: minor
-- expect: known-bug
SET @kasir := (SELECT id FROM users ORDER BY id LIMIT 1);
SET @unit := (SELECT id FROM product_units ORDER BY id LIMIT 1);
SET @produk := (SELECT product_id FROM product_units WHERE id = @unit);
INSERT INTO sales (sale_number, request_token, cashier_id, subtotal, total)
VALUES ('DB-TEST-QTY', 'token-db-test-qty', @kasir, -1000, -1000);
INSERT INTO sale_items (sale_id, product_id, product_code_snapshot, product_name_snapshot, unit_id, unit_name_snapshot,
                        conversion_factor_snapshot, qty_unit, qty_base, unit_price, line_total)
VALUES (LAST_INSERT_ID(), @produk, 'X', 'X', @unit, 'PCS', 1, -1, -1, 1000, -1000);
