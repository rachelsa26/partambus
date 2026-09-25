-- title: Maksimal satu sesi kas terbuka
-- rule: toko hanya punya satu laci kas aktif
-- severity: critical
SELECT COUNT(*) AS sesi_terbuka
FROM cash_sessions
WHERE status = 'open'
HAVING COUNT(*) > 1;
