-- title: Status penjualan di luar daftar ditolak database
-- rule: ENUM sales.status ('completed','void') dengan STRICT mode
-- severity: minor
-- expect: reject
SET @kasir := (SELECT id FROM users ORDER BY id LIMIT 1);
INSERT INTO sales (sale_number, request_token, status, cashier_id, subtotal, total)
VALUES ('DB-TEST-STATUS', 'token-db-test-status', 'refund', @kasir, 1000, 1000);
