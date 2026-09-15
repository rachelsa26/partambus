<?php
declare(strict_types=1);

/**
 * Enriches session cart entries ({product_id, unit_id, qty, discount_amount})
 * with live product/unit/price/stock/cost data. This is the single source of
 * truth for cart totals shared by the cart page and the checkout preview —
 * the final atomic commit still re-validates everything under a row lock.
 *
 * Every line always carries a concrete, priced unit — there is no "unit not
 * chosen yet" state. `available_units` (every other sellable unit for that
 * product) is included so the cart row can offer an "ubah satuan" dropdown
 * to switch units after the fact (see set_unit in pos/index.php), but the
 * line itself is always complete from the moment it's added.
 *
 * @param array<string, array{product_id:int, unit_id:int, qty:int, discount_amount:float}> $cart
 * @return array<int, array<string, mixed>>
 */
function get_cart_lines_with_details(PDO $pdo, array $cart): array
{
    $lines = [];

    foreach ($cart as $key => $entry) {
        $stmt = $pdo->prepare(
            'SELECT pu.id AS unit_id, pu.unit_name, pu.conversion_factor, pu.selling_price, pu.can_sell, pu.active AS unit_active,
                    p.id AS product_id, p.code, p.name, p.base_unit_name, p.active AS product_active, p.current_stock_base, p.current_wac
             FROM product_units pu JOIN products p ON p.id = pu.product_id
             WHERE pu.id = ? AND p.id = ?'
        );
        $stmt->execute([$entry['unit_id'], $entry['product_id']]);
        $row = $stmt->fetch();

        $valid = $row && (int) $row['product_active'] === 1 && (int) $row['unit_active'] === 1 && (int) $row['can_sell'] === 1;

        $qtyUnit = (int) $entry['qty'];
        $discount = (float) $entry['discount_amount'];
        $conversion = $valid ? (int) $row['conversion_factor'] : 0;
        $qtyBase = $qtyUnit * $conversion;
        $unitPrice = $valid ? (float) $row['selling_price'] : 0.0;
        $lineSubtotal = $unitPrice * $qtyUnit;
        $lineTotal = $lineSubtotal - $discount;
        $wac = ($valid && $row['current_wac'] !== null) ? (float) $row['current_wac'] : null;
        $cogsTotal = $wac !== null ? round($wac * $qtyBase, 2) : null;
        $belowCost = $cogsTotal !== null && $lineTotal < $cogsTotal;
        $stockOk = $valid && (int) $row['current_stock_base'] >= $qtyBase;

        $unitsStmt = $pdo->prepare(
            'SELECT id, unit_name, selling_price FROM product_units
             WHERE product_id = ? AND active = 1 AND can_sell = 1 ORDER BY conversion_factor ASC'
        );
        $unitsStmt->execute([$entry['product_id']]);
        $availableUnits = $unitsStmt->fetchAll();

        $lines[] = [
            'key' => $key,
            'valid' => $valid,
            'available_units' => $availableUnits,
            'product_id' => (int) $entry['product_id'],
            'unit_id' => (int) $entry['unit_id'],
            'code' => $row['code'] ?? null,
            'name' => $row['name'] ?? null,
            'unit_name' => $row['unit_name'] ?? null,
            'base_unit_name' => $row['base_unit_name'] ?? null,
            'conversion_factor' => $conversion,
            'qty_unit' => $qtyUnit,
            'qty_base' => $qtyBase,
            'discount_amount' => $discount,
            'unit_price' => $unitPrice,
            'line_subtotal' => $lineSubtotal,
            'line_total' => $lineTotal,
            'unit_cost_snapshot' => $wac,
            'cogs_total' => $cogsTotal,
            'below_cost' => $belowCost,
            'current_stock_base' => $row['current_stock_base'] ?? null,
            'stock_ok' => $stockOk,
        ];
    }

    return $lines;
}

function cart_totals(array $lines): array
{
    $subtotal = 0.0;
    $discountTotal = 0.0;
    foreach ($lines as $line) {
        $subtotal += $line['line_subtotal'];
        $discountTotal += $line['discount_amount'];
    }
    return ['subtotal' => $subtotal, 'discount_total' => $discountTotal, 'total' => $subtotal - $discountTotal];
}

/**
 * Quick cash-amount buttons for the POS payment panel: "Uang Pas" (the
 * exact total) plus up to 3 more options, each the total rounded up to the
 * next Rp10.000/50.000/100.000 — so a Rp31.000 total offers Rp40.000,
 * Rp50.000, Rp100.000 instead of a fixed Rp20.000 that's smaller than the
 * total and useless. Duplicates (a rounding that lands back on the total,
 * or on another step's result) are dropped.
 *
 * @return int[] first element is always the exact total ("Uang Pas")
 */
function pos_quick_cash_amounts(float $total): array
{
    $total = (int) ceil($total);
    $amounts = [$total];

    foreach ([10000, 50000, 100000] as $step) {
        $rounded = (int) (ceil($total / $step) * $step);
        if ($rounded > $total && !in_array($rounded, $amounts, true)) {
            $amounts[] = $rounded;
        }
    }

    return array_slice($amounts, 0, 4);
}
