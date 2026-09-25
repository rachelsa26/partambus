# Menjalankan PARTAMBUS dengan Docker

PARTAMBUS punya dua lingkungan Docker yang terpisah. Keduanya boleh menyala bersamaan.

| | Coding (sehari-hari) | Test (Playwright, CI) |
| --- | --- | --- |
| File | `docker-compose.dev.yml` | `docker-compose.yml` |
| Aplikasi | http://localhost:8000 | http://localhost:8080 |
| Database (HeidiSQL/DBeaver) | `localhost:3308` | `localhost:3307` |
| Adminer (lihat database di browser) | http://localhost:8081 | - |
| Edit kode | Langsung terlihat, cukup refresh browser | Perlu `--build` ulang |
| Data | Milik Anda, tidak pernah direset otomatis | Selalu bersih + data uji (`qa_owner`, `qa_kasir`) |

Login database (keduanya): user `partambus`, password `partambus`, database `partambus`.

Semua perintah di bawah dijalankan dari folder proyek:

```powershell
cd C:\Projects\partambus
```

## Mode coding

```powershell
# Nyalakan (pertama kali butuh beberapa menit untuk build)
docker compose -f docker-compose.dev.yml up -d --build

# Matikan (data tetap aman)
docker compose -f docker-compose.dev.yml down

# Lihat log PHP/Apache kalau ada error
docker compose -f docker-compose.dev.yml logs -f app
```

Database coding pertama kali dibuat dari `database/schema.sql`, jadi hanya berisi user bawaan `owner`.
Untuk memasukkan data yang sudah ada (misalnya data dummy dari laptop lain), lihat bagian berikut.

Setelah `composer.json` atau `docker/Dockerfile` berubah, jalankan:

```powershell
docker compose -f docker-compose.dev.yml up -d --build --renew-anon-volumes
```

## Memasukkan data yang sudah ada (sekali saja)

Data coding tidak ikut ke Git. Kalau Anda punya salinan database (file `.sql`, misalnya `backups/laragon-dump.sql`
dari laptop lama), taruh di folder `backups/` lalu:

1. Nyalakan Docker mode coding (lihat di atas).
2. Import ke database coding:

   ```powershell
   cmd /c "docker compose -f docker-compose.dev.yml exec -T db mariadb -upartambus -ppartambus partambus < backups\laragon-dump.sql"
   ```

3. Buka http://localhost:8000 dan login dengan akun yang ada di data tersebut.

Jika import gagal dengan pesan `Unknown collation: 'utf8mb4_0900_ai_ci'` (dump dari MySQL 8), jalankan ini lalu ulangi langkah 2:

```powershell
(Get-Content backups\laragon-dump.sql -Raw) -replace 'utf8mb4_0900_ai_ci','utf8mb4_unicode_ci' | Set-Content backups\laragon-dump.sql -Encoding utf8
```

Membuat salinan database coding (misalnya sebelum ganti laptop):

```powershell
cmd /c "docker compose -f docker-compose.dev.yml exec -T db mariadb-dump -upartambus -ppartambus partambus > backups\coding-dump.sql"
```

## Mode test

```powershell
docker compose up -d --build --wait     # aplikasi test di http://localhost:8080
cd e2e
npm ci
npx playwright install chromium
npm test
cd ..
docker compose down -v                  # reset database test ke kondisi awal
```

`down -v` hanya menghapus database **test**. Database coding ada di project lain (`partambus-dev`) dan tidak tersentuh.

## Masalah umum

| Gejala | Penyebab dan solusi |
| --- | --- |
| `port is already allocated` | Port dipakai aplikasi lain. Matikan aplikasi itu, atau ganti angka port kiri di file compose. |
| "Gagal terhubung ke database" setelah `up` | Database masih menyala. Tunggu 10-20 detik lalu refresh. |
| Perubahan kode tidak muncul | Pastikan membuka port 8000 (coding), bukan 8080 (test). |
| Ingin mulai ulang database coding dari nol | `docker compose -f docker-compose.dev.yml down -v` (SEMUA data coding hilang). |
