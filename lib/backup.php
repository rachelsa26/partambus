<?php
declare(strict_types=1);

const BACKUP_FORMAT_VERSION = 1;
const BACKUP_DIR = __DIR__ . '/../backups';

/**
 * Writes a full, self-contained SQL dump of every table (structure + data)
 * using plain PDO queries only — no dependency on the mysqldump binary, so
 * it keeps working even if the app is moved to a PC with a different MySQL
 * install (NFR-007).
 *
 * @return array{filename:string, path:string, size:int, table_count:int}
 */
function create_backup(PDO $pdo, string $label = ''): array
{
    if (!is_dir(BACKUP_DIR) && !mkdir(BACKUP_DIR, 0755, true) && !is_dir(BACKUP_DIR)) {
        throw new RuntimeException('Tidak bisa membuat folder backup.');
    }

    $filename = 'partambus_backup_' . date('Ymd_His') . '.sql';
    $filePath = BACKUP_DIR . '/' . $filename;

    $out = fopen($filePath, 'w');
    if ($out === false) {
        throw new RuntimeException('Tidak bisa membuat file backup.');
    }

    fwrite($out, "-- PARTAMBUS_BACKUP\n");
    fwrite($out, '-- PARTAMBUS_BACKUP_VERSION: ' . BACKUP_FORMAT_VERSION . "\n");
    fwrite($out, '-- created_at: ' . date('Y-m-d H:i:s') . "\n");
    fwrite($out, '-- label: ' . str_replace("\n", ' ', $label) . "\n\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($out, "SET NAMES utf8mb4;\n\n");

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $createRow = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
        fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($out, $createRow['Create Table'] . ";\n\n");

        $selectStmt = $pdo->query('SELECT * FROM `' . $table . '`');
        $columns = null;
        $batch = [];
        $batchSize = 200;

        while ($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = array_keys($row);
            }
            $values = array_map(
                static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                $row
            );
            $batch[] = '(' . implode(',', $values) . ')';

            if (count($batch) >= $batchSize) {
                fwrite($out, 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ' . implode(',', $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            fwrite($out, 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ' . implode(',', $batch) . ";\n");
        }
        fwrite($out, "\n");
    }

    fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($out);

    return [
        'filename' => $filename,
        'path' => $filePath,
        'size' => (int) filesize($filePath),
        'table_count' => count($tables),
    ];
}

function validate_backup_file(string $filePath): void
{
    if (!is_file($filePath)) {
        throw new RuntimeException('File backup tidak ditemukan.');
    }
    $handle = fopen($filePath, 'r');
    $header = fread($handle, 4096);
    fclose($handle);

    if ($header === false || !str_contains($header, 'PARTAMBUS_BACKUP')) {
        throw new RuntimeException('File ini bukan file backup PARTAMBUS yang valid.');
    }
}

/**
 * Splits a SQL dump into individual statements, respecting quoted strings
 * (so a `;` inside a text value never causes a bad split).
 *
 * @return string[]
 */
function split_sql_statements(string $sql): array
{
    $lines = explode("\n", $sql);
    $lines = array_filter($lines, static fn ($line) => !str_starts_with(ltrim($line), '--'));
    $sql = implode("\n", $lines);

    $statements = [];
    $current = '';
    $inString = false;
    $quoteChar = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];
        $current .= $char;

        if ($inString) {
            if ($char === '\\' && $i + 1 < $len) {
                $current .= $sql[$i + 1];
                $i++;
                continue;
            }
            if ($char === $quoteChar) {
                $inString = false;
            }
            continue;
        }

        if ($char === "'" || $char === '"') {
            $inString = true;
            $quoteChar = $char;
        } elseif ($char === ';') {
            $trimmed = trim($current, "; \t\n\r\0\x0B");
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
        }
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/** @return int number of statements executed */
function restore_backup(PDO $pdo, string $filePath): int
{
    validate_backup_file($filePath);

    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException('Gagal membaca file backup.');
    }

    $statements = split_sql_statements($sql);
    if (!$statements) {
        throw new RuntimeException('File backup kosong atau tidak berisi perintah SQL yang bisa dijalankan.');
    }

    $executed = 0;
    foreach ($statements as $statement) {
        $pdo->exec($statement);
        $executed++;
    }

    return $executed;
}
