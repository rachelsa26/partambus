-- title: Idempotensi: request_token penjualan ganda ditolak database
-- rule: BR-024 / UNIQUE uq_sales_request_token: klik Bayar dua kali tidak boleh jadi dua transaksi
-- severity: critical
-- expect: reject
SET @kasir := (SELECT id FROM users ORDER BY id LIMIT 1);
INSERT INTO sales (sale_number, request_token, cashier_id, subtotal, total)
VALUES ('DB-TEST-0001', 'token-sama-db-test', @kasir, 1000, 1000);
INSERT INTO sales (sale_number, request_token, cashier_id, subtotal, total)
VALUES ('DB-TEST-0002', 'token-sama-db-test', @kasir, 1000, 1000);
