-- title: Satuan yang bisa dijual selalu punya harga jual
-- rule: BR-009/BR-010: can_sell = 1 wajib punya selling_price > 0
-- severity: normal
SELECT pu.id, p.code, pu.unit_name, pu.selling_price
FROM product_units pu
JOIN products p ON p.id = pu.product_id
WHERE pu.can_sell = 1 AND pu.active = 1 AND (pu.selling_price IS NULL OR pu.selling_price <= 0);
