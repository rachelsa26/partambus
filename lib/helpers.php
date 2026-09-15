<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function rupiah(float|string|null $amount): string
{
    return 'Rp' . number_format((float) $amount, 0, ',', '.');
}

function normalize_code(string $code): string
{
    return strtoupper(trim($code));
}

const INDO_MONTHS = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];

/**
 * Formats a date as "23 Agustus 2026" without depending on the OS having an
 * id_ID locale installed (setlocale is unreliable across Windows installs).
 */
function indo_date(DateTimeInterface $date): string
{
    return $date->format('j') . ' ' . INDO_MONTHS[(int) $date->format('n')] . ' ' . $date->format('Y');
}

const DATE_FORMAT_OPTIONS = [
    'd/m/Y' => 'DD/MM/YYYY',
    'Y-m-d' => 'YYYY-MM-DD',
    'd-m-Y' => 'DD-MM-YYYY',
];

/**
 * Short numeric-date rendering for tables/lists (Riwayat Penjualan, Log
 * Audit, Cash Session, Pembelian, Inventaris, Backup) — driven by the
 * Format Tanggal setting from Pengaturan. Long-form Indonesian dates
 * (indo_date(), used on receipts/dashboard/detail headers) intentionally
 * stay untouched by this setting.
 */
function fmt_date(?string $mysqlDatetime, bool $withTime = true): string
{
    if ($mysqlDatetime === null || $mysqlDatetime === '') {
        return '-';
    }
    static $format = null;
    if ($format === null) {
        global $pdo;
        $format = get_setting($pdo, 'date_format', 'd/m/Y');
        if (!array_key_exists($format, DATE_FORMAT_OPTIONS)) {
            $format = 'd/m/Y';
        }
    }
    $dt = new DateTimeImmutable($mysqlDatetime);
    return $withTime ? $dt->format($format . ' H:i') : $dt->format($format);
}

/**
 * Plain-text struk formatted for a WhatsApp message body — `*bold*` for the
 * store name/total (WhatsApp's own markdown), and a ``` monospace block ```
 * around the item list so the qty/price/subtotal columns stay aligned on
 * screen. Shares its data with the HTML receipt preview (sales/view.php's
 * ajax=receipt) rather than converting one into the other.
 *
 * @param array<string,mixed> $sale
 * @param array<int,array<string,mixed>> $items
 * @param array<string,mixed>|null $payment
 * @param array<string,string> $settings
 * @param array<string,string> $methodLabels
 */
function partambus_receipt_wa_text(array $sale, array $items, ?array $payment, array $settings, array $methodLabels): string
{
    $storeName = $settings['store_name'] ?? 'Toko';
    $lines = ['*' . $storeName . '*'];
    if (!empty($settings['store_address'])) {
        $lines[] = $settings['store_address'];
    }
    if (!empty($settings['store_phone'])) {
        $lines[] = 'Telp. ' . $settings['store_phone'];
    }

    $createdAt = new DateTimeImmutable((string) $sale['created_at']);
    $lines[] = '';
    $lines[] = 'No. Transaksi: ' . $sale['sale_number'];
    $lines[] = 'Tanggal: ' . indo_date($createdAt) . ', ' . $createdAt->format('H:i');
    $lines[] = 'Kasir: ' . $sale['cashier_name'];

    $lines[] = '';
    $lines[] = '```';
    foreach ($items as $it) {
        $lines[] = $it['product_name_snapshot'];
        $qtyPart = '  ' . (int) $it['qty_unit'] . ' x ' . $it['unit_name_snapshot'] . ' @ ' . number_format((float) $it['unit_price'], 0, ',', '.');
        $amountPart = number_format((float) $it['line_total'], 0, ',', '.');
        $gap = max(1, 32 - strlen($qtyPart) - strlen($amountPart));
        $lines[] = $qtyPart . str_repeat(' ', $gap) . $amountPart;
    }
    $lines[] = '```';

    $lines[] = '';
    $lines[] = 'Subtotal: ' . rupiah($sale['subtotal']);
    $lines[] = 'Diskon: -' . rupiah($sale['discount_total']);
    $lines[] = '*TOTAL: ' . rupiah($sale['total']) . '*';

    if ($payment) {
        $lines[] = '';
        $lines[] = 'Metode Bayar: ' . ($methodLabels[$payment['method']] ?? $payment['method']);
        if ($payment['method'] === 'cash') {
            $lines[] = 'Dibayar: ' . rupiah($payment['cash_received']);
            $lines[] = 'Kembalian: ' . rupiah($payment['change_amount']);
        }
    }

    $lines[] = '';
    $lines[] = 'Terima kasih telah berbelanja di *' . $storeName . '*!';

    return implode("\n", $lines);
}

const PAYMENT_METHOD_LABELS = [
    'cash' => 'Tunai',
    'qris' => 'QRIS',
    'dana' => 'Dana',
    'ovo' => 'OVO',
    'gopay' => 'GoPay',
    'transfer' => 'Transfer',
    'debit' => 'Debit',
    'other' => 'Lainnya',
];

function payment_method_label(string $method): string
{
    return PAYMENT_METHOD_LABELS[$method] ?? $method;
}

function redirect(string $path): never
{
    header('Location: ' . APP_BASE_PATH . $path);
    exit;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/**
 * Real, already-verified system alerts (never a fabricated count) — shared
 * by the dashboard's and POS's notification bell so both reflect the exact
 * same conditions: low/out-of-stock products, and (owner only, since
 * backup/index.php is owner-only) an overdue backup.
 *
 * @return array<int, array{type:string, title:string, text:string, href:string}>
 */
function partambus_system_alerts(PDO $pdo, int $lowStockCount, bool $isOwner): array
{
    $alerts = [];

    if ($lowStockCount > 0) {
        $alerts[] = [
            'type' => 'stock',
            'title' => 'Stok rendah',
            'text' => $lowStockCount . ' produk perlu diperhatikan.',
            'href' => APP_BASE_PATH . ($isOwner ? '/inventory/index.php' : '/products/index.php'),
        ];
    }

    if ($isOwner) {
        $backupReminderHours = (int) get_setting($pdo, 'backup_reminder_hours', '24');
        $lastBackup = $pdo->query('SELECT created_at FROM backup_history WHERE status = "success" ORDER BY id DESC LIMIT 1')->fetchColumn() ?: null;
        $backupOverdue = !$lastBackup || (time() - strtotime($lastBackup)) / 3600 > $backupReminderHours;
        if ($backupOverdue) {
            $alerts[] = [
                'type' => 'backup',
                'title' => 'Backup data',
                'text' => $lastBackup ? 'Backup terakhir sudah lama, buat backup baru.' : 'Belum pernah melakukan backup.',
                'href' => APP_BASE_PATH . '/backup/index.php',
            ];
        }
    }

    return $alerts;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}
