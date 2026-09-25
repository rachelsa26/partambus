# PARTAMBUS: Database Test Suite

Test SQL yang memeriksa **isi database**, bukan tampilan. Tampilan "Transaksi berhasil" belum menjamin uang dan
stok tercatat benar; test ini membuktikannya langsung di tabel.

**30 test**: 20 cek integritas data + 10 cek constraint database.

## Cara kerja

| Folder | Isi | Lulus jika |
| --- | --- | --- |
| `integrity/` | Query yang **mencari data rusak** | Query mengembalikan 0 baris |
| `constraints/` | Percobaan menyimpan data salah, di dalam transaksi yang selalu di-`ROLLBACK` | Database menolak (`expect: reject`) |

Setiap file `.sql` diawali header yang dibaca runner:

```sql
-- title: Stok produk sama dengan total ledger pergerakan stok
-- rule: BR-011: products.current_stock_base adalah saldo; stock_movements adalah buku besarnya
-- severity: critical
SELECT ...
```

Test dijalankan **setelah** test API dan UI, sehingga semua transaksi yang baru dibuat ikut diperiksa.

## Yang diperiksa

**Integritas data:** stok = total ledger, rantai saldo ledger tidak putus, tidak ada stok negatif, total item dan header
penjualan cocok, satu pembayaran per penjualan sebesar total, kembalian tunai benar, penjualan tunai tercatat di sesi
kas dan menambah kas, stok berkurang sesuai barang terjual, void mengembalikan stok, format nomor transaksi sesuai
tanggal, maksimal satu sesi kas terbuka, selisih kas saat tutup sesi benar, tidak ada rujukan yatim di ledger, satuan
dasar produk, satuan jual punya harga, kode produk ternormalisasi, total pembelian.

**Constraint:** konversi 0, kode produk ganda (beda huruf besar/kecil), barcode ganda, `request_token` ganda
(idempotensi), nomor transaksi ganda, item tanpa penjualan induk, status di luar daftar.

**Celah yang terdokumentasi (`expect: known-bug`)**: database masih menerima dua sesi kas terbuka, saldo stok negatif,
dan qty item negatif. Aturan ini hanya dijaga kode PHP, bukan database (*defense in depth*). Test lulus selama celah
ada, dan gagal saat celah ditutup sebagai tanda untuk mengubahnya menjadi `expect: reject`.

## Menjalankan

Aplikasi test harus menyala (`docker compose up -d --wait`, database di port 3307).

```bash
cd db-tests
npm ci
npm test
```

Variabel opsional: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SUITES=integrity` (hanya integritas),
`DB_RUN_LABEL` (keterangan di Allure). Hasil Allure ditulis ke `allure-results/` dan ikut masuk dashboard gabungan
(`cd e2e && npm run qa`).

Suite integritas juga bisa dijalankan terhadap **database coding** (data asli, port 3308) untuk mencari data rusak di
data nyata. Constraint aman karena selalu di-rollback:

```bash
DB_PORT=3308 DB_RUN_LABEL="data coding" npm test
```
