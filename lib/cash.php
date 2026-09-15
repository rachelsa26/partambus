<?php
declare(strict_types=1);

function get_active_cash_session(PDO $pdo): ?array
{
    $stmt = $pdo->query('SELECT * FROM cash_sessions WHERE status = "open" ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();
    return $row ?: null;
}

function calculate_expected_cash(PDO $pdo, int $sessionId, float $openingCash): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM cash_movements WHERE cash_session_id = ?');
    $stmt->execute([$sessionId]);
    return $openingCash + (float) $stmt->fetchColumn();
}

function record_cash_movement(
    PDO $pdo,
    int $sessionId,
    string $movementType,
    float $amount,
    ?string $referenceType,
    ?int $referenceId,
    int $actorUserId,
    ?string $reason
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO cash_movements (cash_session_id, movement_type, amount, reference_type, reference_id, actor_user_id, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$sessionId, $movementType, $amount, $referenceType, $referenceId, $actorUserId, $reason]);
}
