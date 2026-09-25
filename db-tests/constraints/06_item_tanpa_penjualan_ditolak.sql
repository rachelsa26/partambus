-- title: Item penjualan tanpa transaksi induk ditolak database
-- rule: FOREIGN KEY fk_sale_items_sale
-- severity: normal
-- expect: reject
SET @unit := (SELECT id FROM product_units ORDER BY id LIMIT 1);
SET @produk := (SELECT product_id FROM product_units WHERE id = @unit);
INSERT INTO sale_items (sale_id, product_id, product_code_snapshot, product_name_snapshot, unit_id, unit_name_snapshot,
                        conversion_factor_snapshot, qty_unit, qty_base, unit_price, line_total)
VALUES (999999999, @produk, 'X', 'X', @unit, 'PCS', 1, 1, 1, 1000, 1000);
