<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$user = require_login();
$pageTitle = 'Dashboard';

const DASHBOARD_RANGES = [
    'today' => ['option' => 'Hari Ini', 'compare' => 'kemarin'],
    'yesterday' => ['option' => 'Kemarin', 'compare' => 'hari sebelumnya'],
    '7d' => ['option' => '7 Hari Terakhir', 'compare' => '7 hari sebelumnya'],
    '30d' => ['option' => '30 Hari Terakhir', 'compare' => '30 hari sebelumnya'],
    'this_month' => ['option' => 'Bulan Ini', 'compare' => 'bulan lalu'],
    'last_month' => ['option' => 'Bulan Lalu', 'compare' => '2 bulan lalu'],
    'custom' => ['option' => 'Custom Range', 'compare' => 'periode sebelumnya'],
];

const DASHBOARD_METRICS = [
    'omzet' => ['label' => 'Omzet', 'kind' => 'money'],
    'gross_profit' => ['label' => 'Gross Profit', 'kind' => 'money'],
    'transaksi' => ['label' => 'Transaksi', 'kind' => 'count'],
    'produk_terjual' => ['label' => 'Produk Terjual', 'kind' => 'count'],
];

$range = (string) ($_GET['range'] ?? 'today');
if (!isset(DASHBOARD_RANGES[$range])) {
    $range = 'today';
}
$customFrom = (string) ($_GET['custom_from'] ?? '');
$customTo = (string) ($_GET['custom_to'] ?? '');

$today = new DateTimeImmutable('today');
$todayFrom = $today->format('Y-m-d 00:00:00');
$todayTo = $today->format('Y-m-d 23:59:59');

/**
 * Resolves a range key (or a custom from/to pair) into concrete date
 * bounds, a matching "previous period of equal length" for %-change
 * comparisons, and the trend chart's granularity — chosen by span length so
 * a custom range gets sensible bucketing too, not just the named presets.
 */
function dashboard_resolve_range(string $range, DateTimeImmutable $today, string $customFrom, string $customTo): array
{
    $customLabel = null;
    switch ($range) {
        case 'yesterday':
            $from = $today->modify('-1 day');
            $to = $from;
            break;
        case '7d':
            $from = $today->modify('-6 days');
            $to = $today;
            break;
        case '30d':
            $from = $today->modify('-29 days');
            $to = $today;
            break;
        case 'this_month':
            $from = $today->modify('first day of this month');
            $to = $today;
            break;
        case 'last_month':
            $from = $today->modify('first day of last month');
            $to = $today->modify('last day of last month');
            break;
        case 'custom':
            try {
                $from = $customFrom !== '' ? new DateTimeImmutable($customFrom) : $today;
            } catch (Exception) {
                $from = $today;
            }
            try {
                $to = $customTo !== '' ? new DateTimeImmutable($customTo) : $today;
            } catch (Exception) {
                $to = $today;
            }
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            if ($to > $today) {
                $to = $today;
            }
            $customLabel = fmt_date($from->format('Y-m-d'), false) . ' - ' . fmt_date($to->format('Y-m-d'), false);
            break;
        case 'today':
        default:
            $range = 'today';
            $from = $today;
            $to = $today;
    }

    $spanDays = $from->diff($to)->days + 1;
    if ($spanDays <= 1) {
        $granularity = 'hour';
    } elseif ($spanDays <= 31) {
        $granularity = 'day';
    } elseif ($spanDays <= 186) {
        $granularity = 'week';
    } else {
        $granularity = 'month';
    }

    $prevTo = $from->modify('-1 day');
    $prevFrom = $prevTo->modify('-' . ($spanDays - 1) . ' days');

    return [
        'range' => $range,
        'from' => $from,
        'to' => $to,
        'granularity' => $granularity,
        'from_sql' => $from->format('Y-m-d 00:00:00'),
        'to_sql' => $to->format('Y-m-d 23:59:59'),
        'prev_from_sql' => $prevFrom->format('Y-m-d 00:00:00'),
        'prev_to_sql' => $prevTo->format('Y-m-d 23:59:59'),
        'custom_label' => $customLabel,
    ];
}

function dashboard_bucket_expr(string $granularity, string $col): string
{
    return match ($granularity) {
        'hour' => "HOUR($col)",
        'day' => "DATE($col)",
        'week' => "YEARWEEK($col, 3)",
        default => "DATE_FORMAT($col, '%Y-%m')",
    };
}

/** @return array<int, array{0: int|string, 1: string}> [bucket key, display label] for every bucket in range, even empty ones */
function dashboard_bucket_walk(array $bounds): array
{
    $granularity = $bounds['granularity'];
    $out = [];

    if ($granularity === 'hour') {
        for ($h = 0; $h < 24; $h++) {
            $out[] = [$h, sprintf('%02d', $h)];
        }
        return $out;
    }

    if ($granularity === 'day') {
        $cursor = $bounds['from'];
        while ($cursor <= $bounds['to']) {
            $out[] = [$cursor->format('Y-m-d'), $cursor->format('d/m')];
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    if ($granularity === 'week') {
        // Mode 3 = ISO-8601 (Monday-start weeks) — matches PHP's format('o')/'W'.
        $cursor = $bounds['from']->modify('monday this week');
        while ($cursor <= $bounds['to']) {
            $key = (int) ($cursor->format('o') . $cursor->format('W'));
            $out[] = [$key, $cursor->format('d/m')];
            $cursor = $cursor->modify('+7 days');
        }
        return $out;
    }

    $cursor = $bounds['from']->modify('first day of this month');
    $endMonth = $bounds['to']->modify('first day of this month');
    while ($cursor <= $endMonth) {
        $label = substr(INDO_MONTHS[(int) $cursor->format('n')], 0, 3) . "'" . $cursor->format('y');
        $out[] = [$cursor->format('Y-m'), $label];
        $cursor = $cursor->modify('+1 month');
    }
    return $out;
}

/**
 * One combined trend series covering all 4 switchable chart metrics at
 * once (omzet, gross_profit, transaksi, produk_terjual) — computed
 * together so switching metrics in the UI is instant with no extra
 * round-trip, instead of firing a separate request per metric.
 *
 * @return array<int, array{label:string, omzet:float, transaksi:int, produk_terjual:int, gross_profit:float}>
 */
function dashboard_trend_points(PDO $pdo, array $bounds): array
{
    $bucketSales = dashboard_bucket_expr($bounds['granularity'], 'created_at');
    $bucketItems = dashboard_bucket_expr($bounds['granularity'], 's.created_at');

    $salesStmt = $pdo->prepare(
        "SELECT $bucketSales AS bucket, COALESCE(SUM(total),0) AS omzet, COUNT(*) AS transaksi
         FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ? GROUP BY $bucketSales"
    );
    $salesStmt->execute([$bounds['from_sql'], $bounds['to_sql']]);
    $salesByBucket = [];
    foreach ($salesStmt->fetchAll() as $row) {
        $salesByBucket[(string) $row['bucket']] = ['omzet' => (float) $row['omzet'], 'transaksi' => (int) $row['transaksi']];
    }

    $itemsStmt = $pdo->prepare(
        "SELECT $bucketItems AS bucket, COALESCE(SUM(si.qty_base),0) AS produk_terjual, COALESCE(SUM(si.cogs_total),0) AS total_cogs
         FROM sale_items si JOIN sales s ON s.id = si.sale_id
         WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY $bucketItems"
    );
    $itemsStmt->execute([$bounds['from_sql'], $bounds['to_sql']]);
    $itemsByBucket = [];
    foreach ($itemsStmt->fetchAll() as $row) {
        $itemsByBucket[(string) $row['bucket']] = ['produk_terjual' => (int) $row['produk_terjual'], 'total_cogs' => (float) $row['total_cogs']];
    }

    $points = [];
    foreach (dashboard_bucket_walk($bounds) as [$key, $label]) {
        $key = (string) $key;
        $s = $salesByBucket[$key] ?? ['omzet' => 0.0, 'transaksi' => 0];
        $i = $itemsByBucket[$key] ?? ['produk_terjual' => 0, 'total_cogs' => 0.0];
        $points[] = [
            'label' => $label,
            'omzet' => $s['omzet'],
            'transaksi' => $s['transaksi'],
            'produk_terjual' => $i['produk_terjual'],
            'gross_profit' => $s['omzet'] - $i['total_cogs'],
        ];
    }
    return $points;
}

/** "Hari Ini, 23 Agustus 2026" / "7 Hari Terakhir, 17 Agustus 2026 - 23 Agustus 2026" */
function dashboard_resolved_label(array $bounds): string
{
    if ($bounds['range'] === 'custom') {
        return indo_date($bounds['from']) . ' - ' . indo_date($bounds['to']);
    }
    $optionLabel = DASHBOARD_RANGES[$bounds['range']]['option'];
    if ($bounds['from']->format('Y-m-d') === $bounds['to']->format('Y-m-d')) {
        return $optionLabel . ', ' . indo_date($bounds['from']);
    }
    return $optionLabel . ', ' . indo_date($bounds['from']) . ' - ' . indo_date($bounds['to']);
}

/** @return float|null null when there's no meaningful baseline to compare against */
function dashboard_pct_change(float $currentValue, float $previousValue): ?float
{
    if ($previousValue <= 0.0) {
        return null;
    }
    return (($currentValue - $previousValue) / $previousValue) * 100;
}

function dashboard_info_tip(string $text): string
{
    return '<span class="info-tip" tabindex="0">' . partambus_icon('info', 13) . '<span class="info-tip-bubble">' . e($text) . '</span></span>';
}

function dashboard_change_badge(?float $pct): string
{
    if ($pct === null) {
        return '<span class="kpi-change kpi-change-neutral">Baru mulai</span>';
    }
    $isUp = $pct >= 0;
    $icon = partambus_icon($isUp ? 'arrow-up' : 'arrow-down', 12);
    $cls = $isUp ? 'kpi-change-up' : 'kpi-change-down';
    return '<span class="kpi-change ' . $cls . '">' . $icon . number_format(abs($pct), 1, ',', '.') . '%</span>';
}

function dashboard_short_rupiah(float $amount): string
{
    if ($amount >= 1000000) {
        return 'Rp' . rtrim(rtrim(number_format($amount / 1000000, 1, ',', '.'), '0'), ',') . 'jt';
    }
    if ($amount >= 1000) {
        return 'Rp' . rtrim(rtrim(number_format($amount / 1000, 1, ',', '.'), '0'), ',') . 'rb';
    }
    return 'Rp' . number_format($amount, 0, ',', '.');
}

/**
 * Batas atas sumbu Y yang "bulat" (kelipatan 1, 2, 2.5, 5 x 10^n per garis),
 * supaya label tidak berulang. Tanpa ini, omzet 0 menghasilkan label
 * Rp0, Rp0, Rp1, Rp1, Rp1 karena skala 0..1 dibagi 4 lalu dibulatkan.
 */
function dashboard_nice_axis_max(float $max, int $steps, float $minStep = 1.0): float
{
    $rawStep = max($max / $steps, $minStep);
    $magnitude = 10 ** floor(log10($rawStep));
    foreach ([1, 2, 2.5, 5, 10] as $factor) {
        if ($rawStep <= $factor * $magnitude) {
            return $factor * $magnitude * $steps;
        }
    }

    return 10 * $magnitude * $steps;
}

/** @param array<int, array<string, mixed>> $points */
function dashboard_trend_chart(array $points, string $metricKey, string $ariaLabel, int $width = 640, int $height = 160): string
{
    $values = array_map(static fn ($p) => (float) $p[$metricKey], $points);
    $steps = 4;
    $isMoney = DASHBOARD_METRICS[$metricKey]['kind'] === 'money';
    // Uang: minimal Rp1rb per garis, supaya omzet kecil/nol tidak berlabel Rp1, Rp2, ...
    $max = dashboard_nice_axis_max((float) max($values), $steps, $isMoney ? 1000.0 : 1.0);
    $padding = ['top' => 16, 'right' => 12, 'bottom' => 26, 'left' => 64];
    $plotW = $width - $padding['left'] - $padding['right'];
    $plotH = $height - $padding['top'] - $padding['bottom'];
    $n = count($points);
    $stepX = $n > 1 ? $plotW / ($n - 1) : 0;
    $labelStep = $n > 10 ? (int) ceil($n / 8) : 1;
    $dotRadius = $n > 20 ? 2.5 : 4;

    $coords = [];
    foreach ($values as $i => $v) {
        $x = $padding['left'] + $i * $stepX;
        $y = $padding['top'] + $plotH - ($v / $max) * $plotH;
        $coords[] = [round($x, 1), round($y, 1)];
    }

    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="trend-chart-svg" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' . e($ariaLabel) . '">';

    for ($s = 0; $s <= $steps; $s++) {
        $val = $max * $s / $steps;
        $y = round($padding['top'] + $plotH - ($s / $steps) * $plotH, 1);
        $label = $isMoney ? dashboard_short_rupiah($val) : number_format($val, 0, ',', '.');
        // style="" (not the stroke/fill attributes) so var(--color-*) is
        // honored — SVG presentation attributes take literal colors only,
        // but CSS inside style="" follows the page's [data-theme], which is
        // what keeps this server-rendered chart in sync with dark mode.
        $svg .= '<line x1="' . $padding['left'] . '" y1="' . $y . '" x2="' . ($width - $padding['right']) . '" y2="' . $y . '" style="stroke:var(--color-border)" stroke-width="1"></line>';
        $svg .= '<text x="' . ($padding['left'] - 10) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="11" style="fill:var(--color-text-muted)">' . e($label) . '</text>';
    }

    $baselineY = round($padding['top'] + $plotH, 1);
    $areaPath = 'M' . $coords[0][0] . ',' . $baselineY;
    foreach ($coords as $c) {
        $areaPath .= ' L' . $c[0] . ',' . $c[1];
    }
    $areaPath .= ' L' . end($coords)[0] . ',' . $baselineY . ' Z';
    $svg .= '<path d="' . $areaPath . '" style="fill:var(--color-primary)" fill-opacity="0.08" stroke="none"></path>';

    $polyline = implode(' ', array_map(static fn ($c) => $c[0] . ',' . $c[1], $coords));
    $svg .= '<polyline points="' . $polyline . '" fill="none" style="stroke:var(--color-primary)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"></polyline>';

    foreach ($coords as $i => $c) {
        $svg .= '<circle cx="' . $c[0] . '" cy="' . $c[1] . '" r="' . $dotRadius . '" style="fill:var(--color-primary);stroke:var(--color-surface)" stroke-width="1.5"></circle>';
        if ($i % $labelStep === 0 || $i === $n - 1) {
            $svg .= '<text x="' . $c[0] . '" y="' . ($height - 6) . '" text-anchor="middle" font-size="11" style="fill:var(--color-text-muted)">' . e($points[$i]['label']) . '</text>';
        }
    }

    return $svg . '</svg>';
}

/** @return array{avg:float, max:float, max_label:string, min:float, min_label:string} */
/** @param array<int, array{label:string, value:float, color:string}> $slices */
function dashboard_donut_chart(array $slices, int $size = 120, int $strokeWidth = 16): string
{
    $total = array_sum(array_column($slices, 'value'));
    $radius = ($size / 2) - ($strokeWidth / 2);
    $circumference = 2 * M_PI * $radius;
    $cx = $size / 2;
    $cy = $size / 2;
    $offset = 0.0;

    $svg = '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">';
    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $radius . '" fill="none" style="stroke:var(--color-border)" stroke-width="' . $strokeWidth . '"></circle>';

    if ($total > 0) {
        foreach ($slices as $slice) {
            $frac = $slice['value'] / $total;
            $dash = $frac * $circumference;
            $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $radius . '" fill="none" stroke="' . e($slice['color']) . '" stroke-width="' . $strokeWidth . '" '
                . 'stroke-dasharray="' . round($dash, 2) . ' ' . round($circumference - $dash, 2) . '" '
                . 'stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 ' . $cx . ' ' . $cy . ')" stroke-linecap="butt"></circle>';
            $offset += $dash;
        }
    }

    return $svg . '</svg>';
}

/**
 * Merges recent sales/cash-session/product events straight from their own
 * tables into one timeline, newest first — this is what powers both the
 * dashboard's "Aktivitas Terbaru" card (small $limit) and its full-list
 * modal (large $limit). Deliberately NOT sourced from the audit log: that
 * log stays reserved for administrative before/after change records, not
 * routine day-to-day activity like sales or opening a cash session.
 *
 * @return array<int, array{ts:string, type:string, title:string, detail:string, actor:string}>
 */
function dashboard_recent_activity(PDO $pdo, int $limit): array
{
    $activity = [];

    $recentSales = $pdo->query(
        'SELECT s.created_at AS ts, s.sale_number, u.full_name AS actor
         FROM sales s JOIN users u ON u.id = s.cashier_id
         WHERE s.status = "completed" ORDER BY s.created_at DESC LIMIT ' . $limit
    )->fetchAll();
    foreach ($recentSales as $row) {
        $activity[] = ['ts' => $row['ts'], 'type' => 'sale', 'title' => 'Penjualan berhasil', 'detail' => 'No. ' . $row['sale_number'], 'actor' => $row['actor']];
    }

    $recentCashOpened = $pdo->query(
        'SELECT cs.opened_at AS ts, cs.id, u.full_name AS actor
         FROM cash_sessions cs JOIN users u ON u.id = cs.opened_by
         ORDER BY cs.opened_at DESC LIMIT ' . $limit
    )->fetchAll();
    foreach ($recentCashOpened as $row) {
        $activity[] = ['ts' => $row['ts'], 'type' => 'cash', 'title' => 'Cash Session dibuka', 'detail' => 'Sesi #' . $row['id'], 'actor' => $row['actor']];
    }

    $recentCashClosed = $pdo->query(
        'SELECT cs.closed_at AS ts, cs.id, u.full_name AS actor
         FROM cash_sessions cs JOIN users u ON u.id = cs.closed_by
         WHERE cs.closed_at IS NOT NULL ORDER BY cs.closed_at DESC LIMIT ' . $limit
    )->fetchAll();
    foreach ($recentCashClosed as $row) {
        $activity[] = ['ts' => $row['ts'], 'type' => 'cash', 'title' => 'Cash Session ditutup', 'detail' => 'Sesi #' . $row['id'], 'actor' => $row['actor']];
    }

    $recentProductsCreated = $pdo->query(
        'SELECT p.created_at AS ts, p.name, u.full_name AS actor
         FROM products p JOIN users u ON u.id = p.created_by
         ORDER BY p.created_at DESC LIMIT ' . $limit
    )->fetchAll();
    foreach ($recentProductsCreated as $row) {
        $activity[] = ['ts' => $row['ts'], 'type' => 'product', 'title' => 'Produk ditambahkan', 'detail' => $row['name'], 'actor' => $row['actor']];
    }

    // Only genuine edits (updated_at moved past created_at) — otherwise every
    // freshly created product would also show up a second time as "diubah".
    $recentProductsUpdated = $pdo->query(
        'SELECT p.updated_at AS ts, p.name, u.full_name AS actor
         FROM products p JOIN users u ON u.id = p.updated_by
         WHERE p.updated_by IS NOT NULL AND p.updated_at > p.created_at
         ORDER BY p.updated_at DESC LIMIT ' . $limit
    )->fetchAll();
    foreach ($recentProductsUpdated as $row) {
        $activity[] = ['ts' => $row['ts'], 'type' => 'product', 'title' => 'Produk diubah', 'detail' => $row['name'], 'actor' => $row['actor']];
    }

    usort($activity, static fn ($a, $b) => strcmp($b['ts'], $a['ts']));
    return array_slice($activity, 0, $limit);
}

const DASHBOARD_PAYMENT_COLORS = ['#2563EB', '#10B981', '#F59E0B', '#3B82F6', '#8B5CF6', '#dc2626', '#64748B'];

// "Stok Menipis" = below threshold but still > 0; "Stok Habis" = exactly 0
// — kept as two distinct counts (not combined into one "needs attention"
// number) so the two KPI cards below can report each state separately.
$lowStockCount = 0;
$outOfStockCount = 0;
$activeCashSession = get_active_cash_session($pdo);

// Bare transaction count (no revenue figures) is shown to every role, always
// "today" — the range filter only applies to the owner's financial widgets.
$todayTxCountStmt = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE status = "completed" AND created_at BETWEEN ? AND ?');
$todayTxCountStmt->execute([$todayFrom, $todayTo]);
$todayTxCount = (int) $todayTxCountStmt->fetchColumn();

if (is_owner()) {
    $stockSummary = $pdo->query(
        'SELECT COUNT(*) AS total_produk,
                COALESCE(SUM(CASE WHEN current_stock_base = 0 THEN 1 ELSE 0 END),0) AS stok_habis,
                COALESCE(SUM(CASE WHEN current_stock_base > 0 AND current_stock_base <= low_stock_threshold_base THEN 1 ELSE 0 END),0) AS stok_rendah,
                COALESCE(SUM(current_stock_base),0) AS total_stok
         FROM products WHERE active = 1'
    )->fetch();
    $lowStockCount = (int) $stockSummary['stok_rendah'];
    $outOfStockCount = (int) $stockSummary['stok_habis'];
} else {
    $lowStockCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM products WHERE active = 1 AND current_stock_base > 0 AND current_stock_base <= low_stock_threshold_base'
    )->fetchColumn();
    $outOfStockCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM products WHERE active = 1 AND current_stock_base = 0'
    )->fetchColumn();
}

// The bell/notification alert stays a single combined "needs attention"
// figure (stok habis is even more urgent than menipis, so it still counts
// toward it) — only the two KPI cards below split them apart visually.
$alerts = partambus_system_alerts($pdo, $lowStockCount + $outOfStockCount, is_owner());

$ajaxMode = $_GET['ajax'] ?? null;

// ---- AJAX: full "produk terlaris" list for the modal, following the same range ----
if ($ajaxMode === 'top_products') {
    if (!is_owner()) {
        exit;
    }
    $bounds = dashboard_resolve_range($range, $today, $customFrom, $customTo);
    $fullTopStmt = $pdo->prepare(
        'SELECT si.product_name_snapshot AS name, SUM(si.qty_base) AS qty
         FROM sale_items si JOIN sales s ON s.id = si.sale_id
         WHERE s.status = "completed" AND s.created_at BETWEEN ? AND ?
         GROUP BY si.product_id, si.product_name_snapshot ORDER BY qty DESC LIMIT 200'
    );
    $fullTopStmt->execute([$bounds['from_sql'], $bounds['to_sql']]);
    $fullTopProducts = $fullTopStmt->fetchAll();

    header('Content-Type: text/html; charset=UTF-8');
    ob_start();
    ?>
    <?php if ($fullTopProducts): ?>
      <table>
        <thead><tr><th style="width:40px">No</th><th>Produk</th><th class="text-right">Jumlah Terjual</th></tr></thead>
        <tbody>
        <?php foreach ($fullTopProducts as $i => $tp): ?>
          <tr><td><?= $i + 1 ?></td><td><?= e($tp['name']) ?></td><td class="text-right"><strong><?= (int) $tp['qty'] ?></strong></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($fullTopProducts) >= 200): ?>
        <p class="text-muted text-small" style="margin-top:10px">Menampilkan 200 produk teratas.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-muted">Belum ada penjualan pada periode ini.</p>
    <?php endif; ?>
    <?php
    echo ob_get_clean();
    exit;
}

// ---- AJAX: full "stok menipis"/"stok habis" lists for their modals — a
// current-state snapshot, so unlike the range-aware endpoint above these
// ignore $range entirely and always reflect right now. Same threshold as
// the "rendah" badge on the Produk page, so the counts here always match
// the KPI cards that open these modals. Menipis explicitly excludes 0 (that
// belongs to the separate "Stok Habis" card/modal instead). ----
if ($ajaxMode === 'low_stock' || $ajaxMode === 'out_of_stock') {
    if (!is_owner()) {
        exit;
    }
    $stockStmt = $ajaxMode === 'low_stock'
        ? $pdo->query(
            'SELECT name, base_unit_name, current_stock_base FROM products
             WHERE active = 1 AND current_stock_base > 0 AND current_stock_base <= low_stock_threshold_base
             ORDER BY current_stock_base ASC, name ASC LIMIT 200'
        )
        : $pdo->query(
            'SELECT name, base_unit_name, current_stock_base FROM products
             WHERE active = 1 AND current_stock_base = 0
             ORDER BY name ASC LIMIT 200'
        );
    $stockProducts = $stockStmt->fetchAll();
    $emptyMessage = $ajaxMode === 'low_stock'
        ? 'Tidak ada produk dengan stok menipis saat ini.'
        : 'Tidak ada produk dengan stok habis saat ini.';

    header('Content-Type: text/html; charset=UTF-8');
    ob_start();
    ?>
    <?php if ($stockProducts): ?>
      <ul class="lowstock-list">
        <?php foreach ($stockProducts as $lp): ?>
          <li>
            <span class="lowstock-name"><?= e($lp['name']) ?></span>
            <span class="lowstock-qty"><?= (int) $lp['current_stock_base'] ?> <?= e($lp['base_unit_name']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($stockProducts) >= 200): ?>
        <p class="text-muted text-small" style="margin-top:10px">Menampilkan 200 produk teratas.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-muted"><?= e($emptyMessage) ?></p>
    <?php endif; ?>
    <?php
    echo ob_get_clean();
    exit;
}

// ---- AJAX: full "Aktivitas Terbaru" list for its modal — same merged
// sales/cash/product timeline as the dashboard card, just uncapped. This is
// intentionally NOT the Log Audit page: that page is reserved for
// administrative before/after change records, this is routine day-to-day
// activity. See dashboard_recent_activity(). ----
if ($ajaxMode === 'recent_activity') {
    if (!is_owner()) {
        exit;
    }
    $fullActivity = dashboard_recent_activity($pdo, 300);

    header('Content-Type: text/html; charset=UTF-8');
    ob_start();
    ?>
    <?php if ($fullActivity): ?>
      <ul class="activity-list">
        <?php foreach ($fullActivity as $act): ?>
          <?php
            $actIcon = ['sale' => 'check', 'cash' => 'money', 'product' => 'box'][$act['type']] ?? 'invoice';
            $ts = new DateTimeImmutable($act['ts']);
            // Full list shows the full date on every row (not just today's
            // shorthand time) — unlike the compact dashboard card, which
            // still only shows bare "H:i" for brevity.
            $timeLabel = indo_date($ts) . ', ' . $ts->format('H:i');
          ?>
          <li>
            <span class="activity-icon activity-icon-<?= e($act['type']) ?>"><?= partambus_icon($actIcon, 15) ?></span>
            <span class="activity-body">
              <span class="activity-title"><?= e($act['title']) ?></span>
              <span class="activity-detail"><?= e($act['detail']) ?></span>
              <span class="activity-meta">oleh <?= e($act['actor']) ?></span>
            </span>
            <span class="activity-time"><?= e($timeLabel) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($fullActivity) >= 300): ?>
        <p class="text-muted text-small" style="margin-top:10px">Menampilkan 300 aktivitas terakhir.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-muted">Belum ada aktivitas.</p>
    <?php endif; ?>
    <?php
    echo ob_get_clean();
    exit;
}

$ownerKpi = null;
$bounds = null;
if (is_owner()) {
    $bounds = dashboard_resolve_range($range, $today, $customFrom, $customTo);
    $rangeLabel = $bounds['custom_label'] ?? DASHBOARD_RANGES[$bounds['range']]['option'];
    $compareLabel = DASHBOARD_RANGES[$bounds['range']]['compare'];

    $salesStmt = $pdo->prepare(
        'SELECT COUNT(*) AS tx_count, COALESCE(SUM(total),0) AS revenue
         FROM sales WHERE status = "completed" AND created_at BETWEEN ? AND ?'
    );
    $salesStmt->execute([$bounds['from_sql'], $bounds['to_sql']]);
    $salesCurrent = $salesStmt->fetch();
    $salesStmt->execute([$bounds['prev_from_sql'], $bounds['prev_to_sql']]);
    $salesPrevious = $salesStmt->fetch();

    $itemsAggStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(si.qty_base),0) AS qty, COALESCE(SUM(si.cogs_total),0) AS cogs
         FROM sale_items si JOIN sales s ON s.id = si.sale_id
         WHERE s.status = "completed" AND s.created_at BETWEEN ? AND ?'
    );
    $itemsAggStmt->execute([$bounds['from_sql'], $bounds['to_sql']]);
    $itemsCurrent = $itemsAggStmt->fetch();
    $itemsAggStmt->execute([$bounds['prev_from_sql'], $bounds['prev_to_sql']]);
    $itemsPrevious = $itemsAggStmt->fetch();

    $grossProfitCurrent = (float) $salesCurrent['revenue'] - (float) $itemsCurrent['cogs'];
    $grossProfitPrevious = (float) $salesPrevious['revenue'] - (float) $itemsPrevious['cogs'];
    $produkTerjualCurrent = (int) $itemsCurrent['qty'];
    $produkTerjualPrevious = (int) $itemsPrevious['qty'];

    $topStmt = $pdo->prepare(
        'SELECT si.product_name_snapshot AS name, SUM(si.qty_base) AS qty
         FROM sale_items si JOIN sales s ON s.id = si.sale_id
         WHERE s.status = "completed" AND s.created_at BETWEEN ? AND ?
         GROUP BY si.product_id, si.product_name_snapshot ORDER BY qty DESC LIMIT 5'
    );
    $topStmt->execute([$bounds['from_sql'], $bounds['to_sql']]);
    $topProducts = $topStmt->fetchAll();

    $paymentStmt = $pdo->prepare(
        'SELECT p.method, COALESCE(SUM(p.amount),0) AS total
         FROM payments p JOIN sales s ON s.id = p.sale_id
         WHERE s.status = "completed" AND s.created_at BETWEEN ? AND ?
         GROUP BY p.method ORDER BY total DESC'
    );
    $paymentStmt->execute([$bounds['from_sql'], $bounds['to_sql']]);
    $paymentRows = $paymentStmt->fetchAll();
    $paymentSlices = [];
    foreach ($paymentRows as $i => $row) {
        $paymentSlices[] = [
            'label' => payment_method_label($row['method']),
            'value' => (float) $row['total'],
            'color' => DASHBOARD_PAYMENT_COLORS[$i % count(DASHBOARD_PAYMENT_COLORS)],
        ];
    }
    $paymentTotal = array_sum(array_column($paymentSlices, 'value'));

    $trendPoints = dashboard_trend_points($pdo, $bounds);

    $ownerKpi = [
        'salesCurrent' => $salesCurrent,
        'salesPrevious' => $salesPrevious,
        'grossProfitCurrent' => $grossProfitCurrent,
        'grossProfitPrevious' => $grossProfitPrevious,
        'produkTerjualCurrent' => $produkTerjualCurrent,
        'produkTerjualPrevious' => $produkTerjualPrevious,
        'topProducts' => $topProducts,
        'trendPoints' => $trendPoints,
        'paymentSlices' => $paymentSlices,
        'paymentTotal' => $paymentTotal,
        'rangeLabel' => $rangeLabel,
        'compareLabel' => $compareLabel,
        'resolvedLabel' => dashboard_resolved_label($bounds),
    ];
}

// The range-dependent widgets are buffered in two pieces so the range
// control can swap both via one AJAX round-trip, without touching the
// "current state" cards (stock summary, recent activity, reminders):
//  - "main": 4 KPI cards + [chart | produk terlaris] side by side
//  - "payment": just Metode Pembayaran's inner content, which visually
//    lives in the static bottom row alongside 3 non-filtered cards, so its
//    card shell stays put and only this inner div gets replaced.
ob_start();
?>
<?php if ($ownerKpi): ?>
  <div class="kpi-grid kpi-grid-6">
    <div class="kpi-card">
      <div class="kpi-icon kpi-icon-primary"><?= partambus_icon('money', 18) ?></div>
      <div class="kpi-label">Omzet <?= dashboard_info_tip('Total nilai penjualan (sebelum dikurangi harga modal) pada periode yang dipilih.') ?></div>
      <div class="kpi-value"><?= rupiah($ownerKpi['salesCurrent']['revenue']) ?></div>
      <?= dashboard_change_badge(dashboard_pct_change((float) $ownerKpi['salesCurrent']['revenue'], (float) $ownerKpi['salesPrevious']['revenue'])) ?>
      <div class="kpi-caption">Dibanding <?= e($ownerKpi['compareLabel']) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon kpi-icon-secondary"><?= partambus_icon('bar', 18) ?></div>
      <div class="kpi-label">Gross Profit <?= dashboard_info_tip('Omzet dikurangi harga modal (HPP) produk yang terjual pada periode ini.') ?></div>
      <div class="kpi-value"><?= rupiah($ownerKpi['grossProfitCurrent']) ?></div>
      <?= dashboard_change_badge(dashboard_pct_change($ownerKpi['grossProfitCurrent'], $ownerKpi['grossProfitPrevious'])) ?>
      <div class="kpi-caption">Dibanding <?= e($ownerKpi['compareLabel']) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon kpi-icon-info"><?= partambus_icon('invoice', 18) ?></div>
      <div class="kpi-label">Transaksi</div>
      <div class="kpi-value"><?= (int) $ownerKpi['salesCurrent']['tx_count'] ?></div>
      <?= dashboard_change_badge(dashboard_pct_change((float) $ownerKpi['salesCurrent']['tx_count'], (float) $ownerKpi['salesPrevious']['tx_count'])) ?>
      <div class="kpi-caption">Dibanding <?= e($ownerKpi['compareLabel']) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon kpi-icon-warning"><?= partambus_icon('box', 18) ?></div>
      <div class="kpi-label">Produk Terjual</div>
      <div class="kpi-value"><?= $ownerKpi['produkTerjualCurrent'] ?></div>
      <?= dashboard_change_badge(dashboard_pct_change((float) $ownerKpi['produkTerjualCurrent'], (float) $ownerKpi['produkTerjualPrevious'])) ?>
      <div class="kpi-caption">Dibanding <?= e($ownerKpi['compareLabel']) ?></div>
    </div>
    <div class="kpi-card kpi-card-clickable" data-open-lowstock-modal tabindex="0" role="button" aria-haspopup="dialog">
      <div class="kpi-icon kpi-icon-danger kpi-icon-squircle"><?= partambus_icon('dot', 18) ?></div>
      <div class="kpi-label">Stok Menipis</div>
      <div class="kpi-value"><?= $lowStockCount ?> produk</div>
      <div class="kpi-caption kpi-caption-danger">Perlu dicek hari ini</div>
    </div>
    <div class="kpi-card kpi-card-clickable" data-open-outofstock-modal tabindex="0" role="button" aria-haspopup="dialog">
      <div class="kpi-icon kpi-icon-danger kpi-icon-squircle"><?= partambus_icon('dot', 18) ?></div>
      <div class="kpi-label">Stok Habis</div>
      <div class="kpi-value"><?= $outOfStockCount ?> produk</div>
      <div class="kpi-caption kpi-caption-danger">Perlu direstock segera</div>
    </div>
  </div>

  <div class="dashboard-grid-chart">
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <h2>Grafik Penjualan</h2>
        <div class="dropdown">
          <button type="button" class="metric-select-btn" data-dropdown-toggle="chart-metric-menu">
            <span data-metric-trigger-label>Omzet</span>
            <?= partambus_icon('chevron-down', 14) ?>
          </button>
          <div class="dropdown-menu hidden" id="chart-metric-menu">
            <?php foreach (DASHBOARD_METRICS as $mKey => $mMeta): ?>
              <a href="#" class="dropdown-item metric-option <?= $mKey === 'omzet' ? 'metric-option-active' : '' ?>" data-metric-option="<?= e($mKey) ?>"><?= e($mMeta['label']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <?php foreach (DASHBOARD_METRICS as $mKey => $mMeta): ?>
        <div class="metric-chart-block <?= $mKey === 'omzet' ? '' : 'hidden' ?>" data-metric-block="<?= e($mKey) ?>">
          <?= dashboard_trend_chart($ownerKpi['trendPoints'], $mKey, 'Grafik ' . $mMeta['label'] . ' ' . $ownerKpi['rangeLabel']) ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between">
        <h2>Produk Terlaris <?= e($ownerKpi['rangeLabel']) ?></h2>
        <div class="kpi-icon kpi-icon-secondary" style="width:32px;height:32px"><?= partambus_icon('shield-check', 16) ?></div>
      </div>
      <?php if ($ownerKpi['topProducts']): ?>
        <ol class="top-product-list top-product-list-ranked">
          <?php foreach ($ownerKpi['topProducts'] as $i => $tp): ?>
            <li><span class="top-product-rank"><?= $i + 1 ?></span><span class="top-product-name"><?= e($tp['name']) ?></span><span class="top-product-qty-badge"><?= (int) $tp['qty'] ?></span></li>
          <?php endforeach; ?>
        </ol>
      <?php else: ?>
        <div class="text-muted" style="margin-top:10px">Belum ada penjualan pada periode ini.</div>
      <?php endif; ?>
      <div class="btn-row">
        <button type="button" class="link-more-btn" data-open-topproducts-modal>Lihat Semua Produk <?= partambus_icon('arrow-right', 14) ?></button>
      </div>
    </div>
  </div>

  <span id="dashboard-resolved-label" class="hidden" data-label="<?= e($ownerKpi['resolvedLabel']) ?>"></span>
<?php endif; ?>
<?php
$dashboardMainHtml = ob_get_clean();

ob_start();
?>
<?php if ($ownerKpi): ?>
  <?php if ($ownerKpi['paymentSlices']): ?>
    <div class="payment-donut-row">
      <?= dashboard_donut_chart($ownerKpi['paymentSlices']) ?>
      <div class="payment-legend">
        <?php foreach ($ownerKpi['paymentSlices'] as $slice): ?>
          <div class="payment-legend-row">
            <span class="payment-legend-dot" style="background:<?= e($slice['color']) ?>"></span>
            <span class="payment-legend-label"><?= e($slice['label']) ?></span>
            <span class="payment-legend-value"><?= rupiah($slice['value']) ?> (<?= $ownerKpi['paymentTotal'] > 0 ? number_format($slice['value'] / $ownerKpi['paymentTotal'] * 100, 0) : 0 ?>%)</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="payment-total-row"><span>Total</span><strong><?= rupiah($ownerKpi['paymentTotal']) ?></strong></div>
  <?php else: ?>
    <div class="text-muted" style="margin-top:10px">Belum ada pembayaran pada periode ini.</div>
  <?php endif; ?>
<?php endif; ?>
<?php
$dashboardPaymentHtml = ob_get_clean();

if ($ajaxMode === '1') {
    if (!is_owner()) {
        exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div data-ajax-part="main">' . $dashboardMainHtml . '</div>';
    echo '<div data-ajax-part="payment">' . $dashboardPaymentHtml . '</div>';
    exit;
}

// ---- Ringkasan Stok, Aktivitas Terbaru, Pengingat: current-state widgets, not range-filtered ----
$recentActivity = is_owner() ? dashboard_recent_activity($pdo, 6) : [];

// ---- topbar: title/subtitle, range dropdown, notification bell reflecting real alerts ----
$topbarSubtitle = 'Pantau performa toko dan aktivitas penjualan.';
$bodyClass = 'dashboard-body';

ob_start();
?>
<?php if (is_owner()): ?>
  <div class="date-pill">
    <?= partambus_icon('calendar', 16) ?>
    <span id="dashboard-date-pill-label"><?= e($ownerKpi['resolvedLabel']) ?></span>
  </div>
  <div class="dropdown" id="dashboard-range-dropdown">
    <button type="button" class="range-trigger-btn" data-dropdown-toggle="range-menu">
      <span id="dashboard-range-trigger-label"><?= e($ownerKpi['rangeLabel']) ?></span>
      <?= partambus_icon('chevron-down', 14) ?>
    </button>
    <div class="dropdown-menu range-dropdown-menu hidden" id="range-menu">
      <?php foreach (DASHBOARD_RANGES as $key => $meta): ?>
        <?php if ($key === 'custom') continue; ?>
        <a href="#" class="dropdown-item range-option <?= $key === $range ? 'range-option-active' : '' ?>" data-range-option="<?= e($key) ?>"><?= e($meta['option']) ?></a>
      <?php endforeach; ?>
      <div class="range-dropdown-divider"></div>
      <div class="range-custom-section">
        <label class="text-small text-muted">Custom Range</label>
        <div class="range-custom-inputs">
          <input type="date" id="dashboard-custom-from" value="<?= e($bounds['from']->format('Y-m-d')) ?>">
          <span>&mdash;</span>
          <input type="date" id="dashboard-custom-to" value="<?= e($bounds['to']->format('Y-m-d')) ?>">
        </div>
        <button type="button" class="btn" id="dashboard-custom-apply-btn" style="width:100%;margin-top:8px">Terapkan</button>
      </div>
    </div>
  </div>
<?php endif; ?>
<div class="dropdown">
  <button type="button" class="notif-bell-btn" data-dropdown-toggle="notif-menu" aria-label="Notifikasi">
    <?= partambus_icon('bell', 18) ?>
    <?php if ($alerts): ?><span class="notif-badge"><?= count($alerts) ?></span><?php endif; ?>
  </button>
  <div class="dropdown-menu hidden" id="notif-menu" style="min-width:240px">
    <?php if (!$alerts): ?>
      <div class="dropdown-item" style="cursor:default;color:var(--color-text-muted)">Tidak ada notifikasi</div>
    <?php else: ?>
      <?php foreach ($alerts as $alert): ?>
        <a class="dropdown-item" href="<?= e($alert['href']) ?>"><?= partambus_icon('warning', 15) ?> <?= e($alert['title']) ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php
$topbarExtra = ob_get_clean();

require __DIR__ . '/includes/header.php';
?>

<?php if ($ownerKpi): ?>

  <div id="dashboard-filtered-region" data-ajax-url="<?= e(APP_BASE_PATH . '/dashboard.php') ?>"><?= $dashboardMainHtml ?></div>

  <div class="dashboard-grid-3col">
    <div class="card">
      <h2>Metode Pembayaran</h2>
      <div id="dashboard-payment-region"><?= $dashboardPaymentHtml ?></div>
    </div>

    <div class="card">
      <h2>Ringkasan Stok</h2>
      <ul class="summary-list">
        <li><span class="summary-list-icon summary-list-icon-primary"><?= partambus_icon('box', 16) ?></span><span class="summary-list-label">Total Produk</span><strong><?= (int) $stockSummary['total_produk'] ?></strong></li>
        <li><span class="summary-list-icon summary-list-icon-warning"><?= partambus_icon('warning', 16) ?></span><span class="summary-list-label">Stok Menipis</span><strong><?= (int) $stockSummary['stok_rendah'] ?></strong></li>
        <li><span class="summary-list-icon summary-list-icon-danger"><?= partambus_icon('x-circle', 16) ?></span><span class="summary-list-label">Stok Habis</span><strong><?= (int) $stockSummary['stok_habis'] ?></strong></li>
        <li><span class="summary-list-icon summary-list-icon-primary"><?= partambus_icon('layers', 16) ?></span><span class="summary-list-label">Total Stok</span><strong><?= number_format((int) $stockSummary['total_stok'], 0, ',', '.') ?></strong></li>
      </ul>
    </div>

    <div class="card">
      <h2>Aktivitas Terbaru</h2>
      <?php if ($recentActivity): ?>
        <ul class="activity-list">
          <?php foreach ($recentActivity as $act): ?>
            <?php
              $actIcon = ['sale' => 'check', 'cash' => 'money', 'product' => 'box'][$act['type']] ?? 'invoice';
              $ts = new DateTimeImmutable($act['ts']);
              $timeLabel = $ts->format('Y-m-d') === $today->format('Y-m-d') ? $ts->format('H:i') : $ts->format('d/m H:i');
            ?>
            <li>
              <span class="activity-icon activity-icon-<?= e($act['type']) ?>"><?= partambus_icon($actIcon, 15) ?></span>
              <span class="activity-body">
                <span class="activity-title"><?= e($act['title']) ?></span>
                <span class="activity-detail"><?= e($act['detail']) ?></span>
                <span class="activity-meta">oleh <?= e($act['actor']) ?></span>
              </span>
              <span class="activity-time"><?= e($timeLabel) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="text-muted" style="margin-top:10px">Belum ada aktivitas.</div>
      <?php endif; ?>
      <div class="btn-row">
        <button type="button" class="link-more-btn" data-open-activity-modal>Lihat Semua Aktivitas <?= partambus_icon('arrow-right', 14) ?></button>
      </div>
    </div>
  </div>

<?php else: ?>

  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-icon kpi-icon-info"><?= partambus_icon('invoice', 18) ?></div>
      <div class="kpi-label">Transaksi Hari Ini</div>
      <div class="kpi-value"><?= $todayTxCount ?></div>
      <div class="kpi-caption">Transaksi selesai hari ini</div>
    </div>
    <?php $lowOrOutCount = $lowStockCount + $outOfStockCount; ?>
    <div class="kpi-card">
      <div class="kpi-icon <?= $lowOrOutCount > 0 ? 'kpi-icon-warning' : 'kpi-icon-secondary' ?>"><?= partambus_icon('box', 18) ?></div>
      <div class="kpi-label">Produk Stok Rendah/Habis</div>
      <div class="kpi-value"><?= $lowOrOutCount ?></div>
      <?php if ($lowOrOutCount > 0): ?>
        <span class="kpi-change kpi-change-down"><?= partambus_icon('warning', 12) ?>Perlu perhatian</span>
      <?php else: ?>
        <span class="kpi-change kpi-change-up"><?= partambus_icon('check', 12) ?>Aman</span>
      <?php endif; ?>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon kpi-icon-primary"><?= partambus_icon('money', 18) ?></div>
      <div class="kpi-label">Status Sesi Kas</div>
      <?php if ($activeCashSession): ?>
        <div class="kpi-value" style="font-size:18px;color:var(--color-secondary)">Terbuka</div>
        <div class="kpi-caption">Sejak <?= e(date('H:i', strtotime($activeCashSession['opened_at']))) ?></div>
      <?php else: ?>
        <div class="kpi-value" style="font-size:18px;color:var(--color-text-muted)">Belum dibuka</div>
        <div class="kpi-caption"><a href="<?= APP_BASE_PATH ?>/cash/open.php">Buka sesi kas</a></div>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>

<div class="card">
  <h2>Akses Cepat</h2>
  <div class="quick-actions">
    <a class="quick-action" href="<?= APP_BASE_PATH ?>/pos/index.php"><?= partambus_icon('cart', 22) ?><span>Kasir (POS)</span></a>
    <a class="quick-action" href="<?= APP_BASE_PATH ?>/sales/index.php"><?= partambus_icon('invoice', 22) ?><span>Riwayat Penjualan</span></a>
    <a class="quick-action" href="<?= APP_BASE_PATH ?>/products/index.php"><?= partambus_icon('box', 22) ?><span>Produk</span></a>
    <a class="quick-action" href="<?= APP_BASE_PATH ?>/cash/index.php"><?= partambus_icon('money', 22) ?><span>Cash Session</span></a>
    <?php if (is_owner()): ?>
      <a class="quick-action" href="<?= APP_BASE_PATH ?>/reports/index.php"><?= partambus_icon('pie', 22) ?><span>Laporan</span></a>
      <a class="quick-action" href="<?= APP_BASE_PATH ?>/purchases/index.php"><?= partambus_icon('truck', 22) ?><span>Pembelian</span></a>
      <a class="quick-action" href="<?= APP_BASE_PATH ?>/backup/index.php"><?= partambus_icon('shield-check', 22) ?><span>Backup & Restore</span></a>
    <?php endif; ?>
  </div>
</div>

<?php if (is_owner()): ?>
<div class="modal-overlay hidden" id="dashboard-topproducts-modal">
  <div class="modal-box" style="max-width:560px;max-height:80vh;overflow-y:auto">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <strong style="font-size:16px">Semua Produk Terlaris</strong>
      <button type="button" class="banner-close" data-modal-cancel aria-label="Tutup">&times;</button>
    </div>
    <div id="dashboard-topproducts-modal-body" style="margin-top:12px"></div>
  </div>
</div>

<div class="modal-overlay hidden" id="dashboard-lowstock-modal">
  <div class="modal-box lowstock-modal-box">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
      <div>
        <strong style="font-size:18px;display:block">Stok Menipis</strong>
        <span class="text-muted" style="font-size:13px">Produk yang perlu direstock</span>
      </div>
      <button type="button" class="banner-close" data-modal-cancel aria-label="Tutup">&times;</button>
    </div>
    <div id="dashboard-lowstock-modal-body" class="lowstock-modal-body"></div>
  </div>
</div>

<div class="modal-overlay hidden" id="dashboard-outofstock-modal">
  <div class="modal-box lowstock-modal-box">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
      <div>
        <strong style="font-size:18px;display:block">Stok Habis</strong>
        <span class="text-muted" style="font-size:13px">Produk yang perlu direstock segera</span>
      </div>
      <button type="button" class="banner-close" data-modal-cancel aria-label="Tutup">&times;</button>
    </div>
    <div id="dashboard-outofstock-modal-body" class="lowstock-modal-body"></div>
  </div>
</div>

<div class="modal-overlay hidden" id="dashboard-activity-modal">
  <div class="modal-box lowstock-modal-box activity-modal-box">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
      <div>
        <strong style="font-size:18px;display:block">Aktivitas Terbaru</strong>
        <span class="text-muted" style="font-size:13px">Penjualan, sesi kas, dan perubahan produk</span>
      </div>
      <button type="button" class="banner-close" data-modal-cancel aria-label="Tutup">&times;</button>
    </div>
    <div id="dashboard-activity-modal-body" class="lowstock-modal-body"></div>
  </div>
</div>
<?php endif; ?>

<footer class="dashboard-footer">
  <span>&copy; <?= e($today->format('Y')) ?> <?= e(APP_NAME) ?>. Semua hak dilindungi.</span>
  <span>Versi <?= e(APP_VERSION) ?></span>
</footer>

<?php require __DIR__ . '/includes/footer.php'; ?>
