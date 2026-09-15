<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_role(['owner']);

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT file_name FROM backup_history WHERE id = ? AND status = "success"');
$stmt->execute([$id]);
$fileName = $stmt->fetchColumn();

if (!$fileName) {
    http_response_code(404);
    die('Backup tidak ditemukan.');
}

$filePath = BACKUP_DIR . '/' . $fileName;
if (!is_file($filePath)) {
    http_response_code(404);
    die('File backup sudah tidak ada di disk (mungkin sudah dipindah/dihapus manual).');
}

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
