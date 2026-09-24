<?php
declare(strict_types=1);

/**
 * Reset password user PARTAMBUS dari command line.
 *
 * Pemakaian (dari folder proyek, saat Docker mode coding menyala):
 *   docker compose -f docker-compose.dev.yml exec app php tools/reset-password.php <username> <password-baru>
 *
 * Hanya bisa dijalankan lewat command line, tidak lewat browser.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

if ($argc !== 3) {
    fwrite(STDERR, "Pemakaian: php tools/reset-password.php <username> <password-baru>\n");
    exit(1);
}

[, $username, $newPassword] = $argv;

if (strlen($newPassword) < 8) {
    fwrite(STDERR, "Password minimal 8 karakter.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE username = ?');
$stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $username]);

if ($stmt->rowCount() === 0) {
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    $exists->execute([$username]);
    if (!$exists->fetch()) {
        fwrite(STDERR, "User '{$username}' tidak ditemukan.\n");
        exit(1);
    }
}

echo "Password untuk '{$username}' berhasil diganti. Silakan login.\n";
