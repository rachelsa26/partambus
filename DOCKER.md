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
cd C:\laragon\www\partambus
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
Untuk memakai data yang sudah ada di Laragon, lihat bagian berikut.

Setelah `composer.json` atau `docker/Dockerfile` berubah, jalankan:

```powershell
docker compose -f docker-compose.dev.yml up -d --build --renew-anon-volumes
```

## Memindahkan data dari Laragon (sekali saja)

1. Nyalakan Laragon (Start All), supaya MySQL Laragon berjalan.
2. Export database Laragon ke `backups/laragon-dump.sql` (folder ini tidak ikut ke Git):

   ```powershell
   $dump = Get-ChildItem C:\laragon\bin\mysql -Recurse -Filter mysqldump.exe | Select-Object -First 1
   & $dump.FullName -u root --routines --default-character-set=utf8mb4 --result-file=backups\laragon-dump.sql partambus
   ```

   Jika MySQL Laragon memakai password, tambahkan `-p` setelah `-u root`.
3. Matikan Laragon (Stop All), lalu nyalakan Docker mode coding (lihat di atas).
4. Import ke database coding:

   ```powershell
   cmd /c "docker compose -f docker-compose.dev.yml exec -T db mariadb -upartambus -ppartambus partambus < backups\laragon-dump.sql"
   ```

5. Buka http://localhost:8000 dan login dengan akun yang biasa Anda pakai di Laragon.

Jika import gagal dengan pesan `Unknown collation: 'utf8mb4_0900_ai_ci'` (dump dari MySQL 8), jalankan ini lalu ulangi langkah 4:

```powershell
(Get-Content backups\laragon-dump.sql -Raw) -replace 'utf8mb4_0900_ai_ci','utf8mb4_unicode_ci' | Set-Content backups\laragon-dump.sql -Encoding utf8
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
| `port is already allocated` | Port dipakai aplikasi lain (misalnya Laragon). Matikan Laragon, atau ganti angka port kiri di file compose. |
| "Gagal terhubung ke database" setelah `up` | Database masih menyala. Tunggu 10-20 detik lalu refresh. |
| Perubahan kode tidak muncul | Pastikan membuka port 8000 (coding), bukan 8080 (test). |
| Ingin mulai ulang database coding dari nol | `docker compose -f docker-compose.dev.yml down -v` (SEMUA data coding hilang). |
