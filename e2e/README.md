# PARTAMBUS: E2E Test Suite

Automated end-to-end tests untuk **PARTAMBUS**, aplikasi POS & manajemen toko retail (PHP 8 + MySQL/MariaDB).
Dibangun dengan **Playwright + TypeScript**, berjalan terhadap aplikasi yang di-container-kan dengan **Docker**, dan
dieksekusi otomatis di **GitHub Actions** setiap push, pull request, dan setiap hari Minggu.

[![E2E Tests](https://github.com/rachelsa26/partambus/actions/workflows/e2e.yml/badge.svg)](https://github.com/rachelsa26/partambus/actions/workflows/e2e.yml)

## Cakupan

| Area             | Yang diuji                                                                                                                                                                  |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Autentikasi      | Login owner/kasir, password salah, user tak terdaftar, akun nonaktif, field kosong, logout, redirect halaman terproteksi                                                    |
| Hak akses (RBAC) | Menu per role, dan **server menolak (403)** 8 halaman khusus owner walau URL dibuka langsung oleh kasir                                                                     |
| Produk           | Tambah produk + verifikasi DB & audit log, kode unik _case-insensitive_, aturan "minimal satu satuan bisa dijual", harga jual wajib lebih dari 0, validasi field wajib      |
| Kasir (POS)      | Scan barcode (auto-add), cari & pilih satuan, jual via QRIS/Transfer, konversi satuan (1 SLOP = 10 PCS) di ledger stok, stok habis memblokir pembayaran                     |
| Sesi kas         | Tunai terkunci tanpa sesi kas, buka sesi, cegah sesi ganda, kembalian tunai, uang kurang ditolak, tutup sesi dengan selisih wajib catatan                                   |
| Keamanan         | CSRF token wajib, file internal (`.sql`, `config/`, `lib/`, `vendor/`, `includes/`) tidak bisa diakses publik, `return_to` tidak bisa dipakai untuk redirect ke domain luar |
| Aksesibilitas    | Dropdown satuan dasar punya label, hasil pencarian POS bisa dipilih dengan keyboard                                                                                         |
| Dashboard        | Label sumbu Y setiap grafik tren tidak berulang                                                                                                                             |

**55 test**, sekitar 35 detik secara paralel, dijalankan dan lulus berulang kali tanpa flaky.

## Arsitektur

```
e2e/
├── src/
│   ├── config/env.ts          # Semua konfigurasi dari env var (.env), dengan default untuk Docker
│   ├── fixtures/test.ts       # Custom fixtures: opsi loginAs, db, api, page objects
│   ├── pages/                 # Page Object Model
│   ├── api/app-client.ts      # Klien HTTP yang paham CSRF: login & setup data tanpa UI
│   ├── db/database.ts         # Assertion langsung ke database (stok, ledger, pembayaran, audit)
│   └── data/factory.ts        # Test data unik per test + data seed
└── tests/
    ├── auth/  products/  pos/  cash/  security/  a11y/  dashboard/
```

Keputusan desain yang disengaja:

- **Login lewat HTTP, bukan UI.** Hanya `login.spec.ts` yang menguji form login. Test lain cukup menulis
  `test.use({ loginAs: 'cashier' })`; fixture login via `AppClient` (GET form → ambil CSRF token → POST), jadi lebih
  cepat dan tiap test mendapat **sesi PHP sendiri**. Ini penting
  karena keranjang POS disimpan di session: `storageState` bersama akan membuat test paralel saling menimpa keranjang.
- **UI action → DB assertion.** Test POS tidak berhenti di "halaman sukses tampil"; test juga memeriksa `sales`,
  `payments`, dan ledger `stock_movements` di database.
- **Aman untuk paralel.** Data yang dibuat test diberi suffix unik. Stok diverifikasi dari baris ledger milik transaksi
  itu sendiri, bukan selisih saldo, karena test lain bisa menjual produk yang sama pada saat bersamaan.
- **State global diisolasi secara eksplisit.** Aplikasi hanya mengizinkan satu sesi kas terbuka. Skenario kas
  dikumpulkan dalam satu `describe` mode serial yang diawali reset kondisi, bukan diandalkan pada urutan file.
- **Konvensi Page Object:** Page Object berisi locator dan aksi; `expect` di dalamnya hanya untuk menunggu aksi
  selesai, kecuali method `expect...` yang dipakai ulang. Verifikasi hasil ditulis di test, dipecah dengan `test.step`
  supaya langkahnya terbaca di report.
- **Locator berbasis aksesibilitas** (`getByRole`, `getByLabel`). Kalau sebuah elemen tidak bisa ditemukan lewat role
  atau label, itu diperlakukan sebagai bug aksesibilitas aplikasi dan diperbaiki, bukan diakali dengan CSS selector.
- **Setiap bug yang diperbaiki punya test regresi.** Test ditulis dulu sampai gagal karena bug, lalu lulus setelah
  perbaikan, sehingga bug yang sama tidak bisa muncul lagi tanpa ketahuan.

## Menjalankan

Prasyarat: Docker Desktop dan Node.js 20+.

```bash
# 1. Jalankan aplikasi + database (dari root repo)
docker compose up -d --build --wait      # app: http://localhost:8080

# 2. Install & jalankan test
cd e2e
npm ci
npx playwright install chromium
npm test                  # semua test
npm run test:smoke        # hanya @smoke
npx playwright test --grep @pos
npm run test:ui           # mode UI interaktif
npm run report            # buka HTML report terakhir

# Reset database ke kondisi awal
docker compose down -v
```

Database di-seed otomatis dari `database/schema.sql` + `docker/db/02-test-data.sql` (user uji `qa_owner`, `qa_kasir`,
`qa_nonaktif`, dan produk dengan stok yang sudah diketahui).

## CI/CD

`.github/workflows/e2e.yml`:

1. **Lint & typecheck:** `tsc`, ESLint (`eslint-plugin-playwright`), Prettier.
2. **E2E:** build image aplikasi, jalankan MariaDB, lalu test dibagi ke **2 shard paralel**.
3. **Report:** blob report dari semua shard digabung menjadi satu HTML report (dengan trace, video, dan screenshot untuk
   test yang gagal), lalu dipublikasikan ke **GitHub Pages** dari branch `main`.

Retry hanya aktif di CI (1x). Test yang lulus setelah retry tercatat sebagai _flaky_ di report, sebagai sinyal untuk
diperbaiki, bukan disembunyikan.
