# PARTAMBUS: E2E Test Suite

Automated end-to-end tests untuk **PARTAMBUS**, aplikasi POS & manajemen toko retail (PHP 8 + MySQL/MariaDB).
Dibangun dengan **Playwright + TypeScript**, berjalan terhadap aplikasi yang di-container-kan dengan **Docker**, dan
dieksekusi otomatis di **GitHub Actions** setiap push, pull request, dan setiap malam.

[![E2E Tests](https://github.com/rachelsa26/partambus/actions/workflows/e2e.yml/badge.svg)](https://github.com/rachelsa26/partambus/actions/workflows/e2e.yml)

## Cakupan

| Area             | Yang diuji                                                                                                                                              |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Autentikasi      | Login owner/kasir, password salah, user tak terdaftar, akun nonaktif, field kosong, logout, redirect halaman terproteksi                                |
| Hak akses (RBAC) | Menu per role, dan **server menolak (403)** 8 halaman khusus owner walau URL dibuka langsung oleh kasir                                                 |
| Produk           | Tambah produk + verifikasi DB & audit log, kode unik _case-insensitive_, aturan "minimal satu satuan bisa dijual", validasi field wajib                 |
| Kasir (POS)      | Scan barcode (auto-add), cari & pilih satuan, jual via QRIS/Transfer, konversi satuan (1 SLOP = 10 PCS) di ledger stok, stok habis memblokir pembayaran |
| Sesi kas         | Tunai terkunci tanpa sesi kas, buka sesi, cegah sesi ganda, kembalian tunai, uang kurang ditolak, tutup sesi dengan selisih wajib catatan               |
| Keamanan         | CSRF token wajib, file internal (`.sql`, `config/`, `lib/`) tidak bisa diakses publik, 2 bug keamanan terdokumentasi                                    |

**49 test**, sekitar 30 detik secara paralel, dijalankan dan lulus berulang kali tanpa flaky.

## Arsitektur

```
e2e/
├── src/
│   ├── config/env.ts          # Semua konfigurasi dari env var (.env), dengan default untuk Docker
│   ├── fixtures/test.ts       # Custom fixtures: asOwner, asCashier, db, api, page objects
│   ├── pages/                 # Page Object Model
│   ├── api/app-client.ts      # Klien HTTP yang paham CSRF: login & setup data tanpa UI
│   ├── db/database.ts         # Assertion langsung ke database (stok, ledger, pembayaran, audit)
│   └── data/factory.ts        # Test data unik per test + data seed
└── tests/
    ├── auth/  products/  pos/  cash/  security/
```

Keputusan desain yang disengaja:

- **Login lewat HTTP, bukan UI.** Hanya `login.spec.ts` yang menguji form login. Test lain login via `AppClient`
  (GET form → ambil CSRF token → POST), jadi lebih cepat dan tiap test mendapat **sesi PHP sendiri**. Ini penting
  karena keranjang POS disimpan di session: `storageState` bersama akan membuat test paralel saling menimpa keranjang.
- **UI action → DB assertion.** Test POS tidak berhenti di "halaman sukses tampil"; test juga memeriksa `sales`,
  `payments`, dan ledger `stock_movements` di database.
- **Aman untuk paralel.** Data yang dibuat test diberi suffix unik. Stok diverifikasi dari baris ledger milik transaksi
  itu sendiri, bukan selisih saldo, karena test lain bisa menjual produk yang sama pada saat bersamaan.
- **State global diisolasi secara eksplisit.** Aplikasi hanya mengizinkan satu sesi kas terbuka. Skenario kas
  dikumpulkan dalam satu `describe` mode serial yang diawali reset kondisi, bukan diandalkan pada urutan file.
- **Locator berbasis aksesibilitas** (`getByRole`, `getByLabel`). Tempat yang terpaksa memakai CSS selector diberi
  komentar, karena itu sekaligus temuan aksesibilitas (lihat bawah).
- **Bug yang diketahui = test yang ditandai `test.fail()`.** Test tetap hijau selama bug ada. Saat bug diperbaiki, test
  akan "unexpectedly passed", jadi `test.fail()` dihapus dan test berubah menjadi penjaga regresi.

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

## Temuan selama pengujian

| #   | Severity | Temuan                                                                                                                                                                                           | Status            |
| --- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------- |
| 1   | Critical | `config/database.php` memakai `const X = getenv(...)`. PHP tidak mengizinkan pemanggilan fungsi di ekspresi `const`, sehingga **semua halaman fatal error**. Perbaikan: ganti dengan `define()`. | Sudah diperbaiki  |
| 2   | Medium   | **Open redirect:** `suppliers/create.php` meneruskan `return_to` ke header `Location` tanpa validasi; `//evil.example` mengarahkan user ke domain lain setelah menyimpan.                        | Test `@known-bug` |
| 3   | Low      | `vendor/composer/installed.json` bisa diunduh publik dan membocorkan versi library.                                                                                                              | Test `@known-bug` |
| 4   | Low      | File partial seperti `includes/header.php` bisa diakses langsung dan menghasilkan HTTP 500.                                                                                                      | Dicatat           |
| 5   | Low (UX) | Nilai negatif ditampilkan sebagai `Rp-1.000`, bukan `-Rp1.000`.                                                                                                                                  | Dicatat           |
| 6   | A11y     | Dropdown "Satuan Dasar" tidak punya label yang terhubung (`<label for>`).                                                                                                                        | Dicatat           |
| 7   | A11y     | Hasil pencarian POS berupa `<div>` yang hanya bisa diklik mouse, tidak bisa dipilih lewat keyboard.                                                                                              | Dicatat           |
