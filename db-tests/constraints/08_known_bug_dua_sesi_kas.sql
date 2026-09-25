-- title: [KNOWN BUG] Database menerima dua sesi kas terbuka sekaligus
-- rule: aturan "satu sesi kas terbuka" hanya dijaga kode PHP, tidak oleh database (tidak ada unique/trigger)
-- severity: minor
-- expect: known-bug
INSERT INTO cash_sessions (opened_by, opening_cash, status) SELECT id, 0, 'open' FROM users ORDER BY id LIMIT 1;
INSERT INTO cash_sessions (opened_by, opening_cash, status) SELECT id, 0, 'open' FROM users ORDER BY id LIMIT 1;
