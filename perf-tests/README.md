# Tes performa PARTAMBUS (k6)

Tes beban untuk alur utama toko: **kasir melayani pelanggan** (cari produk, scan barcode, masukkan keranjang, bayar QRIS) dan **owner memantau** (dashboard, riwayat penjualan, laporan).
Ditulis dengan [k6](https://k6.io) (JavaScript). Setiap skenario punya **threshold**: target waktu respons dan tingkat error yang harus tercapai. Kalau target tidak tercapai, k6 keluar dengan status gagal.

## Skenario

| Skenario       | Perintah         | Beban                               | Durasi    | Tujuan                                                                               |
| -------------- | ---------------- | ----------------------------------- | --------- | ------------------------------------------------------------------------------------ |
| Smoke          | `npm run smoke`  | 1 kasir + 1 owner                   | ±15 detik | Memastikan skrip dan aplikasi berfungsi sebelum test berat. Semua check harus 100%.  |
| Race condition | `npm run race`   | 30 kasir bayar di detik yang sama   | ±15 detik | Stok tinggal 10: harus **tepat 10 terjual, 20 ditolak**, stok akhir 0 (tidak minus). |
| Load           | `npm run load`   | naik bertahap ke 10 kasir + 2 owner | ±5 menit  | Beban hari ramai (±5x toko normal). p95 < 800 ms, error < 1%.                        |
| Stress         | `npm run stress` | naik bertahap ke 50 kasir + 3 owner | ±7 menit  | Mencari titik mulai melambat/error. Berhenti otomatis kalau error > 5%.              |

Smoke dan race ikut dijalankan di GitHub Actions dan oleh `npm run qa`.
Load dan stress **tidak** dijalankan di CI: mesin GitHub dipakai bersama sehingga angkanya tidak konsisten; jalankan di laptop.

### Threshold load test

| Metrik                                | Target                                              |
| ------------------------------------- | --------------------------------------------------- |
| Request gagal (`http_req_failed`)     | < 1%                                                |
| Check lulus / checkout berhasil       | > 99%                                               |
| Semua request                         | p95 < 800 ms, p99 < 1500 ms                         |
| Pencarian produk (search-as-you-type) | p95 < 500 ms                                        |
| Scan barcode                          | p95 < 300 ms                                        |
| Tambah ke keranjang                   | p95 < 800 ms                                        |
| Checkout (bayar)                      | p95 < 1000 ms                                       |
| Login                                 | p95 < 1000 ms (hash password memang sengaja lambat) |
| Laporan penjualan                     | p95 < 1500 ms                                       |

## Menjalankan

Butuh k6 (sekali saja):

```powershell
winget install k6 --source winget
```

Aplikasi test harus menyala (`docker compose up -d --wait` dari folder utama), lalu dari folder `perf-tests`:

```powershell
npm run smoke
npm run load
```

Target lain bisa dipilih dengan environment variable, misalnya lingkungan dev di port 8000:

```powershell
k6 run -e BASE_URL=http://localhost:8000 -e CASHIER_USER=... -e CASHIER_PASS=... scenarios/smoke.js
```

Variabel yang tersedia: `BASE_URL`, `CASHIER_USER`, `CASHIER_PASS`, `OWNER_USER`, `OWNER_PASS`, `PRODUCT_BARCODE`, `SEARCH_TERMS`, `MIN_STOCK`, `RACE_BUYERS`, `RACE_STOCK`. Jangan jalankan load atau stress ke server produksi.

## Hasil

Setiap run menghasilkan:

- **Ringkasan di terminal**, termasuk daftar threshold LULUS/GAGAL dan check yang gagal.
- `reports/<skenario>-summary.json`: semua metrik mentah.
- `reports/load-dashboard.html` / `reports/stress-dashboard.html`: grafik k6 web dashboard (waktu respons, jumlah pengguna, request per detik sepanjang test).
- `allure-results/`: setiap threshold menjadi satu test di dashboard Allure gabungan (grup **Performance**, bersebelahan dengan UI, API, dan DB).

## Desain

- **Stok disiapkan otomatis.** `setup()` login sebagai owner dan menambah stok produk uji lewat halaman Penyesuaian Stok, jadi stok tidak habis di tengah test dan tetap tercatat di ledger. Test database yang dijalankan setelahnya tetap lulus.
- **Sesi seperti kasir sungguhan.** Setiap pengguna virtual login sekali lalu melayani banyak pelanggan (`noCookiesReset`); keranjang disimpan di sesi PHP masing-masing.
- **Metrik per endpoint.** Request diberi tag `name`, sehingga URL seperti `?q=kopi` dan `?q=rokok` dihitung sebagai satu endpoint "cari" dan bisa diberi threshold sendiri.
- **Metrik bisnis.** Selain waktu respons, dihitung jumlah transaksi yang tersimpan (`sales_created`) dan persentase checkout berhasil (`checkout_success`).
- **Tidak ada data kosong yang dianggap lulus.** k6 menganggap threshold lulus kalau metriknya tidak punya data; ringkasan di sini menandainya `KOSONG` dan Allure mencatatnya sebagai _broken_.

## Struktur

```
perf-tests/
├── lib/
│   ├── config.js       # URL, user, produk uji (bisa diganti lewat env)
│   ├── partambus.js    # langkah pengguna: login, cari, scan, keranjang, bayar, atur stok
│   └── summary.js      # ringkasan terminal + JSON + hasil Allure
└── scenarios/
    ├── _shared.js      # perjalanan kasir & owner yang dipakai semua skenario
    ├── smoke.js
    ├── race.js
    ├── load.js
    └── stress.js
```
