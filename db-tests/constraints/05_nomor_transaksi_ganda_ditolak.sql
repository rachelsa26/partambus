-- title: Nomor transaksi ganda ditolak database
-- rule: UNIQUE uq_sales_number
-- severity: critical
-- expect: reject
SET @kasir := (SELECT id FROM users ORDER BY id LIMIT 1);
INSERT INTO sales (sale_number, request_token, cashier_id, subtotal, total)
VALUES ('DB-TEST-SAMA', 'token-db-test-a', @kasir, 1000, 1000);
INSERT INTO sales (sale_number, request_token, cashier_id, subtotal, total)
VALUES ('DB-TEST-SAMA', 'token-db-test-b', @kasir, 1000, 1000);
