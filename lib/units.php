<?php
declare(strict_types=1);

/** @return array<int, array{id:int, code:string, description:?string}> */
function get_active_units(PDO $pdo): array
{
    return $pdo->query('SELECT id, code, description FROM units WHERE active = 1 ORDER BY sort_order, code')->fetchAll();
}

/** Case-insensitive, trimmed lookup. Does not create anything. */
function find_unit_by_text(PDO $pdo, string $rawText): ?array
{
    $normalized = strtoupper(trim($rawText));
    if ($normalized === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, code, description FROM units WHERE code_normalized = ?');
    $stmt->execute([$normalized]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Looks up a unit by text; if it doesn't exist yet, creates it (this is how
 * the "Lainnya" manual-entry flow grows the reference list over time).
 * Returns the canonical code to store on products/product_units.unit_name.
 */
function find_or_create_unit(PDO $pdo, string $rawText): string
{
    $trimmed = trim($rawText);
    $existing = find_unit_by_text($pdo, $trimmed);
    if ($existing) {
        return $existing['code'];
    }

    $normalized = strtoupper($trimmed);
    $stmt = $pdo->prepare(
        'INSERT INTO units (code, code_normalized, sort_order) VALUES (?, ?, 999)
         ON DUPLICATE KEY UPDATE code = code'
    );
    $stmt->execute([$trimmed, $normalized]);

    return $trimmed;
}

/**
 * Resolves a unit <select> + optional "Lainnya" free-text pair submitted
 * together from a form. Returns null (with $error set) if invalid.
 */
function resolve_unit_input(PDO $pdo, string $selectValue, string $otherValue, string $fieldLabel, ?string &$error): ?string
{
    if ($selectValue === '__other__') {
        $custom = trim($otherValue);
        if ($custom === '') {
            $error = $fieldLabel . ': isi nama satuan barunya karena memilih "Lainnya".';
            return null;
        }
        return find_or_create_unit($pdo, $custom);
    }

    if ($selectValue === '') {
        $error = $fieldLabel . ': pilih satuan.';
        return null;
    }

    return $selectValue;
}

/**
 * Renders a unit <select> (+ hidden "Lainnya" text input, toggled by app.js)
 * as an HTML string, ensuring $currentValue always has a matching <option>
 * even if it isn't part of the standard reference list (legacy/custom data).
 *
 * @param array<int, array{code:string, description:?string}> $unitsList
 */
function render_unit_select(array $unitsList, string $currentValue, string $selectName, string $otherName, bool $disabled = false, string $style = '', string $id = '', string $ariaLabel = ''): string
{
    $codes = array_column($unitsList, 'code');
    $currentValue = trim($currentValue);
    $hasCurrent = $currentValue === '' || in_array($currentValue, $codes, true);

    $html = '<div data-unit-select-wrapper' . ($style !== '' ? ' style="' . e($style) . '"' : '') . '>';
    // id menghubungkan <label for> ke dropdown; aria-label dipakai di baris unit
    // tambahan yang labelnya tidak bisa memakai for (id-nya dibuat dinamis).
    $html .= '<select name="' . e($selectName) . '" data-unit-select'
        . ($id !== '' ? ' id="' . e($id) . '"' : '')
        . ($ariaLabel !== '' ? ' aria-label="' . e($ariaLabel) . '"' : '')
        . ($disabled ? ' disabled' : '') . '>';
    $html .= '<option value="">-- pilih satuan --</option>';

    if (!$hasCurrent) {
        $html .= '<option value="' . e($currentValue) . '" selected>' . e($currentValue) . ' (sudah dipakai, tidak ada di daftar standar)</option>';
    }

    foreach ($unitsList as $u) {
        $selected = $u['code'] === $currentValue ? ' selected' : '';
        $label = $u['code'] . ($u['description'] ? ' — ' . $u['description'] : '');
        $html .= '<option value="' . e($u['code']) . '"' . $selected . '>' . e($label) . '</option>';
    }

    $html .= '<option value="__other__">Lainnya...</option>';
    $html .= '</select>';
    $html .= '<input type="text" name="' . e($otherName) . '" data-unit-other-input placeholder="Nama satuan baru" aria-label="Nama satuan baru" style="display:none;margin-top:6px" ' . ($disabled ? 'disabled' : '') . '>';
    if ($disabled) {
        // disabled fields never submit — carry the locked value forward explicitly
        $html .= '<input type="hidden" name="' . e($selectName) . '" value="' . e($currentValue) . '">';
    }
    $html .= '</div>';

    return $html;
}
