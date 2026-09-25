-- title: Kembalian tunai benar dan non-tunai tidak punya kembalian
-- rule: tunai: cash_received >= amount dan change_amount = cash_received - amount; non-tunai: keduanya NULL
-- severity: critical
SELECT id, sale_id, method, amount, cash_received, change_amount
FROM payments
WHERE (method = 'cash' AND (cash_received IS NULL OR cash_received < amount
                            OR change_amount <> cash_received - amount))
   OR (method <> 'cash' AND (cash_received IS NOT NULL OR change_amount IS NOT NULL));
