-- title: Sesi kas tertutup punya selisih dan kas yang diharapkan yang benar
-- rule: expected_cash = kas awal + SUM(cash_movements); difference = actual_cash - expected_cash
-- severity: critical
SELECT cs.id, cs.opening_cash, cs.expected_cash, cs.actual_cash, cs.difference, cs.closed_by, cs.closed_at,
       cs.opening_cash + COALESCE(SUM(cm.amount), 0) AS seharusnya
FROM cash_sessions cs
LEFT JOIN cash_movements cm ON cm.cash_session_id = cs.id
WHERE cs.status = 'closed'
GROUP BY cs.id, cs.opening_cash, cs.expected_cash, cs.actual_cash, cs.difference, cs.closed_by, cs.closed_at
HAVING cs.closed_by IS NULL OR cs.closed_at IS NULL
    OR cs.expected_cash <> seharusnya
    OR cs.difference <> cs.actual_cash - cs.expected_cash;
