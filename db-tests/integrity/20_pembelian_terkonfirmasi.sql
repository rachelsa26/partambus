-- title: Pembelian terkonfirmasi punya total sesuai item dan pencatat
-- rule: purchases.total_amount = SUM(purchase_items.subtotal); confirmed_by & confirmed_at terisi
-- severity: normal
SELECT pu.id, pu.purchase_number, pu.total_amount, COALESCE(SUM(pi.subtotal), 0) AS total_item,
       pu.confirmed_by, pu.confirmed_at
FROM purchases pu
LEFT JOIN purchase_items pi ON pi.purchase_id = pu.id
WHERE pu.status = 'confirmed'
GROUP BY pu.id, pu.purchase_number, pu.total_amount, pu.confirmed_by, pu.confirmed_at
HAVING pu.total_amount <> total_item OR pu.confirmed_by IS NULL OR pu.confirmed_at IS NULL;
