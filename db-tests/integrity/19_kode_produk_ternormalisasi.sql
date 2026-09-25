-- title: Kode produk ternormalisasi (dasar keunikan tanpa beda huruf besar/kecil)
-- rule: code_normalized = UPPER(TRIM(code))
-- severity: normal
SELECT id, code, code_normalized
FROM products
WHERE code_normalized <> UPPER(TRIM(code)) COLLATE utf8mb4_bin;
