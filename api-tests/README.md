# PARTAMBUS: API Test Suite

Test API/HTTP untuk PARTAMBUS dengan **Postman**, dijalankan otomatis oleh **Newman** di GitHub Actions.
PARTAMBUS bukan REST API: sebagian besar endpoint adalah form POST dengan CSRF token yang membalas redirect,
ditambah dua endpoint JSON di kasir. Suite ini menguji kontrak HTTP-nya langsung, tanpa browser.

**53 request, 127 assertion**, sekitar 2 detik.

| Folder | Yang diuji |
| --- | --- |
| 01 Autentikasi | Login gagal (password salah, akun nonaktif, field kosong), login tanpa CSRF (403), cookie sesi `HttpOnly` + `SameSite=Lax` dan ID sesi diganti setelah login, logout mengakhiri sesi |
| 02 Kasir | Endpoint JSON scan barcode (ada, tidak dikenal, stok habis, kosong), validasi keranjang (qty 0, desimal, unit bukan milik produk), checkout ditolak untuk token palsu / metode tidak dikenal / tanpa CSRF, checkout QRIS, **idempotensi** (kirim ulang checkout tidak membuat transaksi kedua), kasir ditolak (403) saat void, buka Pengguna, atau menambah produk lewat POST langsung |
| 03 Sesi kas | Tunai ditolak tanpa sesi kas, validasi kas awal, cegah sesi ganda, uang kurang ditolak, kembalian tunai, tutup sesi dengan selisih wajib alasan |
| 04 Owner | Void wajib alasan, void berhasil, void kedua tidak diproses ulang, supplier tanpa CSRF / tanpa nama ditolak, bug open redirect (#2) terdokumentasi |

## Teknik yang dipakai

- **Chaining request:** CSRF token, `request_token` (kunci idempotensi), `product_id`, dan `sale_id` diambil dari respons lalu disimpan sebagai variabel untuk request berikutnya.
- **Redirect tidak diikuti** (`followRedirects: false`), supaya status 302 dan header `Location` bisa diperiksa.
- **Aman diulang:** tiap folder mulai dengan logout, dan folder sesi kas mulai dengan menutup sesi yang mungkin masih terbuka.
- **Label Allure** `// @allure.label.epic:API` di tiap script, supaya hasil API tampil dalam grup `API` di dashboard Allure gabungan (UI + API).
- **Bug yang diketahui** diberi label `[KNOWN BUG #n]` dan memastikan bug *masih ada*. Saat bug diperbaiki, test gagal sebagai tanda untuk membalik assertion menjadi penjaga regresi.

## Menjalankan

Aplikasi test harus menyala: `docker compose up -d --build --wait` dari folder utama proyek (http://localhost:8080).

**Postman:** Import `partambus.postman_collection.json` dan `docker-test.postman_environment.json`, pilih environment
"PARTAMBUS - Docker test", lalu klik kanan collection > **Run collection**. Jalankan berurutan, karena request saling bergantung.

**Newman (command line):**

```bash
cd api-tests
npm ci
npm test        # laporan HTML: reports/api-report.html, hasil Allure: allure-results/
```
