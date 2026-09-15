<?php
declare(strict_types=1);

/**
 * Appends one stock movement and updates the product's running balance.
 * MUST be called inside a transaction the caller controls (this function
 * takes a row lock via SELECT ... FOR UPDATE to keep the balance race-free).
 * Throws RuntimeException if the resulting balance would go negative (BR-014).
 *
 * @return array{movement_id:int, balance_after:int}
 */
function record_stock_movement(
    PDO $pdo,
    int $productId,
    string $movementType,
    int $qtyDeltaBase,
    ?float $costPerBase,
    ?string $referenceType,
    ?int $referenceId,
    int $actorUserId,
    ?string $reason
): array {
    $lockStmt = $pdo->prepare('SELECT current_stock_base FROM products WHERE id = ? FOR UPDATE');
    $lockStmt->execute([$productId]);
    $row = $lockStmt->fetch();
    if (!$row) {
        throw new RuntimeException('Produk tidak ditemukan.');
    }

    $newBalance = (int) $row['current_stock_base'] + $qtyDeltaBase;
    if ($newBalance < 0) {
        throw new RuntimeException('Stok tidak mencukupi. Sisa saat ini: ' . $row['current_stock_base'] . '.');
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO stock_movements
            (product_id, movement_type, qty_delta_base, balance_after_base, cost_per_base, reference_type, reference_id, actor_user_id, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertStmt->execute([
        $productId, $movementType, $qtyDeltaBase, $newBalance, $costPerBase, $referenceType, $referenceId, $actorUserId, $reason,
    ]);

    $updateStmt = $pdo->prepare('UPDATE products SET current_stock_base = ? WHERE id = ?');
    $updateStmt->execute([$newBalance, $productId]);

    return ['movement_id' => (int) $pdo->lastInsertId(), 'balance_after' => $newBalance];
}
