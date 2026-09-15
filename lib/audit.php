<?php
declare(strict_types=1);

function log_audit(
    PDO $pdo,
    string $action,
    string $entityType,
    ?int $entityId,
    ?array $before = null,
    ?array $after = null,
    ?string $reason = null,
    ?string $batchId = null
): void {
    $actorId = current_user()['id'] ?? null;

    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_value, after_value, reason, batch_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $actorId,
        $action,
        $entityType,
        $entityId,
        $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
        $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
        $reason,
        $batchId,
    ]);
}

/**
 * Human-readable label + badge color class for every action this codebase's
 * log_audit() calls use. Falls back to the raw action string (still shown in
 * a neutral badge) for anything not listed here, so a future new action type
 * never renders as blank.
 */
const AUDIT_ACTION_LABELS = [
    'product_created' => ['label' => 'Produk Dibuat', 'class' => 'badge-active'],
    'product_updated' => ['label' => 'Produk Diperbarui', 'class' => 'badge-info'],
    'product_deleted' => ['label' => 'Produk Dihapus', 'class' => 'badge-danger'],
    'bulk_import_completed' => ['label' => 'Import Selesai', 'class' => 'badge-purple'],
    'price_changed' => ['label' => 'Harga Diubah', 'class' => 'badge-info'],
    'wac_manual_adjustment' => ['label' => 'Harga Modal Disesuaikan Manual', 'class' => 'badge-low-stock'],
    'unit_created' => ['label' => 'Satuan Ditambahkan', 'class' => 'badge-active'],
    'unit_deleted' => ['label' => 'Satuan Dihapus', 'class' => 'badge-danger'],
    'initial_stock_recorded' => ['label' => 'Stok Awal Dicatat', 'class' => 'badge-active'],
    'stock_adjustment' => ['label' => 'Penyesuaian Stok', 'class' => 'badge-low-stock'],
    'purchase_draft_created' => ['label' => 'Draft Pembelian Dibuat', 'class' => 'badge-inactive'],
    'purchase_confirmed' => ['label' => 'Pembelian Dikonfirmasi', 'class' => 'badge-active'],
    'purchase_draft_cancelled' => ['label' => 'Draft Pembelian Dibatalkan', 'class' => 'badge-danger'],
    'sale_voided' => ['label' => 'Transaksi Divoid', 'class' => 'badge-danger'],
    'cash_session_opened' => ['label' => 'Sesi Kas Dibuka', 'class' => 'badge-active'],
    'cash_session_closed' => ['label' => 'Sesi Kas Ditutup', 'class' => 'badge-inactive'],
    'cash_manual_movement' => ['label' => 'Kas Manual Masuk/Keluar', 'class' => 'badge-info'],
    'backup_created' => ['label' => 'Backup Dibuat', 'class' => 'badge-active'],
    'database_restored' => ['label' => 'Database Dipulihkan', 'class' => 'badge-danger'],
    'user_created' => ['label' => 'Pengguna Dibuat', 'class' => 'badge-active'],
    'user_updated' => ['label' => 'Pengguna Diperbarui', 'class' => 'badge-info'],
    'supplier_created' => ['label' => 'Supplier Dibuat', 'class' => 'badge-active'],
    'supplier_updated' => ['label' => 'Supplier Diperbarui', 'class' => 'badge-info'],
    'settings_updated' => ['label' => 'Pengaturan Diperbarui', 'class' => 'badge-info'],
];

/** @return array{label:string, class:string} */
function audit_action_label(string $action): array
{
    return AUDIT_ACTION_LABELS[$action] ?? ['label' => $action, 'class' => 'badge-inactive'];
}

/** Where a click on the "Entitas" column should go, or null if that entity type has no detail page. */
function audit_entity_url(string $entityType, ?int $entityId, ?int $resolvedProductId = null): ?string
{
    if ($entityId === null) {
        return null;
    }
    switch ($entityType) {
        case 'product':
            return APP_BASE_PATH . '/products/edit.php?id=' . $entityId;
        case 'product_unit':
            return $resolvedProductId !== null ? APP_BASE_PATH . '/products/edit.php?id=' . $resolvedProductId : null;
        case 'user':
            return APP_BASE_PATH . '/users/edit.php?id=' . $entityId;
        case 'supplier':
            return APP_BASE_PATH . '/suppliers/edit.php?id=' . $entityId;
        case 'purchase':
            return APP_BASE_PATH . '/purchases/view.php?id=' . $entityId;
        case 'sale':
            return APP_BASE_PATH . '/sales/view.php?id=' . $entityId;
        default:
            return null;
    }
}

const AUDIT_ENTITY_LABELS = [
    'product' => 'Produk',
    'product_unit' => 'Satuan Produk',
    'purchase' => 'Pembelian',
    'sale' => 'Transaksi',
    'cash_session' => 'Sesi Kas',
    'user' => 'Pengguna',
    'supplier' => 'Supplier',
    'app_settings' => 'Pengaturan',
    'backup' => 'Backup',
];

function audit_entity_label(string $entityType): string
{
    return AUDIT_ENTITY_LABELS[$entityType] ?? $entityType;
}

/** Renders a single before/after value for human display (not raw JSON). */
function audit_format_value(mixed $value): string
{
    if ($value === null) {
        return '(kosong)';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    return (string) $value;
}

/**
 * Fields that are noise at the per-row level for a given action — e.g.
 * "units_saved" on a bulk-import product_created row describes the whole
 * import batch, not that one product, and reads as "always 0/irrelevant"
 * when shown per-product. "source" is dropped too since which action ran
 * (now shown as its own labeled badge) already says where the row came from.
 */
const AUDIT_HIDDEN_FIELDS = [
    'product_created' => ['units_saved', 'source'],
];

/**
 * Compares an audit log's before/after JSON and reduces it to just what a
 * human needs to see. Two shapes come out of this codebase's log_audit()
 * calls:
 *  - real before+after pairs (product_updated, user_updated, sale_voided,
 *    ...) -> diffed field-by-field, unchanged fields dropped entirely.
 *  - creation/summary entries where before is null (product_created,
 *    bulk_import_completed, ...) -> nothing to diff against, so the after
 *    payload is just listed as-is (there IS no "before" for a brand new row).
 *
 * @return array{mode: 'none'|'summary'|'diff', lines: array<int, array{field:string, value?:string, before?:string, after?:string}>}
 */
function audit_diff_summary(?string $beforeJson, ?string $afterJson, string $action = ''): array
{
    $before = $beforeJson !== null ? json_decode($beforeJson, true) : null;
    $after = $afterJson !== null ? json_decode($afterJson, true) : null;
    $hiddenFields = AUDIT_HIDDEN_FIELDS[$action] ?? [];

    if (!is_array($before) && !is_array($after)) {
        return ['mode' => 'none', 'lines' => []];
    }

    if (!is_array($before) || !is_array($after)) {
        $data = is_array($after) ? $after : $before;
        $lines = [];
        foreach ($data as $field => $value) {
            if (in_array($field, $hiddenFields, true)) {
                continue;
            }
            $lines[] = ['field' => (string) $field, 'value' => audit_format_value($value)];
        }
        return ['mode' => 'summary', 'lines' => $lines];
    }

    $lines = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
        if (in_array($field, $hiddenFields, true)) {
            continue;
        }
        $beforeVal = array_key_exists($field, $before) ? $before[$field] : null;
        $afterVal = array_key_exists($field, $after) ? $after[$field] : null;
        if (json_encode($beforeVal) === json_encode($afterVal)) {
            continue; // unchanged — deliberately omitted so the view stays short
        }
        $lines[] = ['field' => (string) $field, 'before' => audit_format_value($beforeVal), 'after' => audit_format_value($afterVal)];
    }
    return ['mode' => 'diff', 'lines' => $lines];
}

/**
 * True if $before and $after describe at least one real field-level change.
 * Used to skip logging a before/after "update" action entirely when nothing
 * actually changed (e.g. opening a product's edit form and clicking Save
 * without touching anything) — that case is not audit-worthy activity, just
 * log noise.
 */
function audit_has_real_change(array $before, array $after): bool
{
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
        $beforeVal = array_key_exists($field, $before) ? $before[$field] : null;
        $afterVal = array_key_exists($field, $after) ? $after[$field] : null;
        if (json_encode($beforeVal) !== json_encode($afterVal)) {
            return true;
        }
    }
    return false;
}
