-- title: [KNOWN BUG] Database menerima saldo stok negatif
-- rule: BR-014 hanya dijaga fungsi record_stock_movement() di PHP; kolom tidak punya CHECK (current_stock_base >= 0)
-- severity: minor
-- expect: known-bug
UPDATE products SET current_stock_base = -1 ORDER BY id LIMIT 1;
