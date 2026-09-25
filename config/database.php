<?php
declare(strict_types=1);

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'partambus');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    // Samakan zona waktu sesi database dengan PHP (Asia/Jakarta, lihat bootstrap.php).
    // Tanpa ini, NOW()/CURRENT_TIMESTAMP memakai jam server database (sering UTC di
    // hosting), sehingga created_at bisa jatuh di tanggal berbeda dengan nomor
    // transaksi yang dibuat dari jam PHP.
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $e) {
    http_response_code(500);
    die('Gagal terhubung ke database. Pastikan server database menyala dan database "partambus" sudah dibuat. Detail: ' . htmlspecialchars($e->getMessage()));
}
