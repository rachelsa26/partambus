-- title: Faktor konversi 0 ditolak database
-- rule: CHECK chk_product_units_conversion_positive (conversion_factor > 0)
-- severity: critical
-- expect: reject
SET @produk := (SELECT id FROM products ORDER BY id LIMIT 1);
INSERT INTO product_units (product_id, unit_name, conversion_factor, is_base, can_sell, selling_price)
VALUES (@produk, 'TEST-NOL', 0, 0, 0, NULL);
