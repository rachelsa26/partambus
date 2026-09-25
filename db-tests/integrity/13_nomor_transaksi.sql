-- title: Nomor transaksi berformat PREFIX-YYYYMMDD-NNNN sesuai tanggal transaksi
-- rule: OQ-C06: contoh SL-20260925-0001; tanggal di nomor = tanggal created_at
-- severity: normal
SELECT id, sale_number, created_at
FROM sales
WHERE sale_number NOT REGEXP '^[A-Z]+-[0-9]{8}-[0-9]{4}$'
   OR SUBSTRING_INDEX(SUBSTRING_INDEX(sale_number, '-', 2), '-', -1) <> DATE_FORMAT(created_at, '%Y%m%d');
