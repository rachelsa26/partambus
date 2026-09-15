<?php
declare(strict_types=1);

/**
 * Weighted Average Cost formula (PRD Section 21).
 * new_wac = ((old_stock * old_wac) + (incoming_base_qty * incoming_cost_per_base)) / (old_stock + incoming_base_qty)
 * Special case: if old_stock <= 0 or old_wac unknown, new WAC = incoming cost per base.
 */
function calculate_new_wac(?float $oldWac, int $oldStockBase, int $incomingBaseQty, float $incomingCostPerBase): float
{
    if ($oldStockBase <= 0 || $oldWac === null) {
        return $incomingCostPerBase;
    }
    return (($oldStockBase * $oldWac) + ($incomingBaseQty * $incomingCostPerBase)) / ($oldStockBase + $incomingBaseQty);
}
