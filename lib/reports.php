<?php
declare(strict_types=1);

/**
 * Resolves a report date-range preset into concrete SQL-ready bounds.
 * All ranges are whole calendar days in the app's configured timezone.
 *
 * @return array{from:string, to:string, from_date:string, to_date:string, preset:string}
 */
function resolve_date_range(string $preset, string $customFrom = '', string $customTo = ''): array
{
    $today = new DateTimeImmutable('today');

    switch ($preset) {
        case 'yesterday':
            $from = $today->modify('-1 day');
            $to = $from;
            break;
        case 'week':
            $from = $today->modify('monday this week');
            $to = $today;
            break;
        case 'month':
            $from = $today->modify('first day of this month');
            $to = $today;
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
            break;
        case 'today':
        default:
            $preset = 'today';
            $from = $today;
            $to = $today;
    }

    return [
        'preset' => $preset,
        'from' => $from->format('Y-m-d 00:00:00'),
        'to' => $to->format('Y-m-d 23:59:59'),
        'from_date' => $from->format('Y-m-d'),
        'to_date' => $to->format('Y-m-d'),
    ];
}

const REPORT_DATE_PRESETS = [
    'today' => 'Hari Ini',
    'yesterday' => 'Kemarin',
    'week' => 'Minggu Ini',
    'month' => 'Bulan Ini',
    'custom' => 'Kustom',
];
