<?php
declare(strict_types=1);

/**
 * Atomically generates the next PREFIX-YYYYMMDD-0001 style number for today,
 * using a per-day counter table (sale_number_counters / purchase_number_counters).
 * $counterTable is always one of our own fixed table name literals, never user input.
 */
function generate_daily_number(PDO $pdo, string $counterTable, string $prefix): string
{
    $today = date('Y-m-d');
    $stmt = $pdo->prepare(
        "INSERT INTO {$counterTable} (counter_date, last_sequence) VALUES (?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE last_sequence = LAST_INSERT_ID(last_sequence + 1)"
    );
    $stmt->execute([$today]);
    $sequence = (int) $pdo->lastInsertId();

    return sprintf('%s-%s-%04d', $prefix, date('Ymd'), $sequence);
}
