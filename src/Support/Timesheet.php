<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * A timesheet ("timeseddel"): the clocked sessions of a period as ONE LINE PER DAY — the times worked and the hours — with a total, as a CSV
 * for a spreadsheet or a printable page for a customer. The numbers come from the work clock's own sessions; an unfinished session counts up to
 * now and is marked, one that was never clocked out is not counted (and the caller is told). Optional rounding of each day to 5 / 10 / 15 / 30
 * minutes, for customers who bill in quarters. Pure.
 */
final class Timesheet
{
    private const WEEKDAYS = [
        'da' => [1 => 'Mandag', 2 => 'Tirsdag', 3 => 'Onsdag', 4 => 'Torsdag', 5 => 'Fredag', 6 => 'Lørdag', 7 => 'Søndag'],
        'en' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
    ];

    private const T = [
        'da' => ['title' => 'Timeseddel', 'date' => 'Dato', 'weekday' => 'Ugedag', 'times' => 'Tidspunkter', 'hours' => 'Timer', 'decimal' => 'Timer (decimal)',
            'notes' => 'Beskrivelse', 'total' => 'I alt', 'running' => 'igangværende', 'person' => 'Navn', 'customer' => 'Kunde', 'job' => 'Arbejdssted', 'period' => 'Periode',
            'rounded' => 'Hver dag er rundet til nærmeste %d min.', 'break' => 'Pause', 'break_note' => 'Der er fratrukket %d min. frokostpause på dage med mere end %s timers arbejde.', 'days' => 'dage', 'print' => 'Udskriv / gem som PDF', 'made' => 'Udarbejdet', 'sign' => 'Underskrift / godkendt'],
        'en' => ['title' => 'Timesheet', 'date' => 'Date', 'weekday' => 'Weekday', 'times' => 'Times', 'hours' => 'Hours', 'decimal' => 'Hours (decimal)',
            'notes' => 'Description', 'total' => 'Total', 'running' => 'running', 'person' => 'Name', 'customer' => 'Customer', 'job' => 'Workplace', 'period' => 'Period',
            'rounded' => 'Each day is rounded to the nearest %d min.', 'break' => 'Break', 'break_note' => '%d min. lunch break deducted on days with more than %s hours worked.', 'days' => 'days', 'print' => 'Print / save as PDF', 'made' => 'Prepared', 'sign' => 'Signature / approved'],
    ];

    /** The allowed roundings of a day, in minutes (0 = exact). */
    // (Gemini accepts only STRING enums — never put this list in a tool schema as an enum.)
    public const ROUNDINGS = [0, 5, 10, 15, 30];

    /**
     * @param list<array{date:string, in:string, out:?string, ongoing:bool, minutes:int}> $sessions WorkEvents::summary() sessions
     * @param array<string, string> $notes date → what was done that day (optional)
     * @return array{days: list<array{date:string, weekday:int, intervals:list<array{in:string, out:?string, ongoing:bool, minutes:int}>, minutes:int, counted:int, note:?string}>, total_minutes:int, round:int}
     */
    public static function build(array $sessions, int $round = 0, array $notes = [], int $break = 0, int $breakOver = 240): array
    {
        $round = in_array($round, self::ROUNDINGS, true) ? $round : 0;
        $break = max(0, min($break, 240));
        $breakOver = max(0, $breakOver);
        $byDay = [];
        foreach ($sessions as $s) {
            $byDay[$s['date']][] = ['in' => $s['in'], 'out' => $s['out'], 'ongoing' => (bool) $s['ongoing'], 'minutes' => (int) $s['minutes']];
        }
        ksort($byDay);
        $days = [];
        $total = 0;
        foreach ($byDay as $date => $intervals) {
            usort($intervals, static fn (array $a, array $b): int => strcmp($a['in'], $b['in']));
            $minutes = array_sum(array_column($intervals, 'minutes'));
            $cut     = $break > 0 && $minutes > $breakOver ? min($break, $minutes) : 0;
            $net     = $minutes - $cut;
            $counted = $round > 0 ? (int) (round($net / $round) * $round) : $net;
            $total  += $counted;
            $days[]  = ['date' => $date, 'weekday' => (int) (new DateTimeImmutable($date))->format('N'), 'intervals' => $intervals, 'minutes' => $minutes, 'break' => $cut, 'counted' => $counted,
                'note' => isset($notes[$date]) && trim($notes[$date]) !== '' ? trim($notes[$date]) : null];
        }

        return ['days' => $days, 'total_minutes' => $total, 'round' => $round, 'break_minutes' => $break, 'break_over' => $breakOver];
    }

    /** "9:34" */
    public static function hm(int $minutes): string
    {
        return intdiv($minutes, 60) . ':' . sprintf('%02d', $minutes % 60);
    }

    /** "9,57" (Danish) or "9.57" */
    public static function decimal(int $minutes, string $lang): string
    {
        $s = number_format($minutes / 60, 2, $lang === 'da' ? ',' : '.', '');

        return $s;
    }

    /** "07:48–17:22, 18:00–19:00 (running)" */
    public static function times(array $day, string $lang): string
    {
        $t = self::T[$lang] ?? self::T['da'];

        return implode(', ', array_map(static fn (array $i): string => $i['in'] . '–' . ($i['out'] ?? $t['running']), $day['intervals']));
    }

    /**
     * CSV rows (Excel-friendly: the caller writes them with ';' and a BOM; hours with a decimal comma for Danish).
     *
     * @param array<string, mixed> $sheet build()
     * @return list<list<string>>
     */
    public static function csvRows(array $sheet, string $lang = 'da', bool $withNotes = false): array
    {
        $lang = isset(self::T[$lang]) ? $lang : 'da';
        $t    = self::T[$lang];
        $brk  = ($sheet['break_minutes'] ?? 0) > 0;
        $head = $brk ? [$t['date'], $t['weekday'], $t['times'], $t['break'], $t['hours'], $t['decimal']] : [$t['date'], $t['weekday'], $t['times'], $t['hours'], $t['decimal']];
        if ($withNotes) {
            $head[] = $t['notes'];
        }
        $rows = [$head];
        foreach ($sheet['days'] as $d) {
            $row = $brk
                ? [$d['date'], self::WEEKDAYS[$lang][$d['weekday']], self::times($d, $lang), self::hm($d['break']), self::hm($d['counted']), self::decimal($d['counted'], $lang)]
                : [$d['date'], self::WEEKDAYS[$lang][$d['weekday']], self::times($d, $lang), self::hm($d['counted']), self::decimal($d['counted'], $lang)];
            if ($withNotes) {
                $row[] = (string) $d['note'];
            }
            $rows[] = $row;
        }
        $foot = $brk
            ? [$t['total'], '', '', self::hm(array_sum(array_column($sheet['days'], 'break'))), self::hm($sheet['total_minutes']), self::decimal($sheet['total_minutes'], $lang)]
            : [$t['total'], '', '', self::hm($sheet['total_minutes']), self::decimal($sheet['total_minutes'], $lang)];
        if ($withNotes) {
            $foot[] = '';
        }
        $rows[] = $foot;

        return $rows;
    }

    /**
     * A standalone printable page (A4, black on white) — "Print / save as PDF" in the browser.
     *
     * @param array<string, mixed> $sheet build()
     * @param array{title?:string, person?:string, customer?:string, job?:string, period?:string, made?:string} $head
     */
    public static function html(array $sheet, string $lang, array $head, bool $withNotes = false, bool $signature = false): string
    {
        $lang = isset(self::T[$lang]) ? $lang : 'da';
        $t    = self::T[$lang];
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = ($head['title'] ?? '') !== '' ? (string) $head['title'] : $t['title'];
        $meta = '';
        foreach (['person' => 'person', 'customer' => 'customer', 'job' => 'job', 'period' => 'period'] as $k => $label) {
            if (($head[$k] ?? '') !== '') {
                $meta .= '<div><span>' . $e($t[$label]) . '</span> ' . $e((string) $head[$k]) . '</div>';
            }
        }
        $brk  = ($sheet['break_minutes'] ?? 0) > 0;
        $rows = '';
        foreach ($sheet['days'] as $d) {
            $rows .= '<tr><td>' . $e(date('d.m.Y', (int) strtotime($d['date']))) . '</td><td>' . $e(self::WEEKDAYS[$lang][$d['weekday']]) . '</td><td>' . $e(self::times($d, $lang)) . '</td>'
                . ($withNotes ? '<td>' . $e((string) $d['note']) . '</td>' : '')
                . ($brk ? '<td class="n">' . $e($d['break'] > 0 ? self::hm($d['break']) : '–') . '</td>' : '')
                . '<td class="n">' . $e(self::hm($d['counted'])) . '</td><td class="n">' . $e(self::decimal($d['counted'], $lang)) . '</td></tr>';
        }
        $cols = ($withNotes ? 6 : 5) + ($brk ? 1 : 0);
        $round = ($brk ? '<p class="note">' . $e(sprintf($t['break_note'], $sheet['break_minutes'], rtrim(rtrim(number_format($sheet['break_over'] / 60, 2, $lang === 'da' ? ',' : '.', ''), '0'), ',.'))) . '</p>' : '')
            . ($sheet['round'] > 0 ? '<p class="note">' . $e(sprintf($t['rounded'], $sheet['round'])) . '</p>' : '');

        return '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($title) . '</title><style>'
            . 'body{font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#111;background:#fff;margin:0;padding:28px;max-width:820px}'
            . 'h1{font-size:22px;margin:0 0 10px}.meta{display:grid;gap:2px;margin-bottom:16px}.meta span{color:#666;display:inline-block;min-width:92px}'
            . 'table{border-collapse:collapse;width:100%}th,td{padding:7px 9px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#555;border-bottom:2px solid #111}'
            . '.n{text-align:right;white-space:nowrap}tfoot td{font-weight:700;border-top:2px solid #111;border-bottom:0}.note{color:#555;font-size:12px}'
            . '.sign{margin-top:46px;display:flex;gap:40px}.sign div{flex:1;border-top:1px solid #111;padding-top:4px;font-size:12px;color:#555}'
            . '.bar{margin:0 0 18px}.bar button{font:inherit;padding:8px 14px;border:1px solid #111;background:#fff;border-radius:8px;cursor:pointer}'
            . '@media print{.bar{display:none}body{padding:0}}'
            . '</style></head><body><div class="bar"><button onclick="window.print()">' . $e($t['print']) . '</button></div>'
            . '<h1>' . $e($title) . '</h1><div class="meta">' . $meta . '</div>'
            . '<table><thead><tr><th>' . $e($t['date']) . '</th><th>' . $e($t['weekday']) . '</th><th>' . $e($t['times']) . '</th>' . ($withNotes ? '<th>' . $e($t['notes']) . '</th>' : '')
            . ($brk ? '<th class="n">' . $e($t['break']) . '</th>' : '')
            . '<th class="n">' . $e($t['hours']) . '</th><th class="n">' . $e($t['decimal']) . '</th></tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr><td colspan="' . ($cols - 2 - ($brk ? 1 : 0)) . '">' . $e($t['total']) . ' (' . count($sheet['days']) . ' ' . $e($t['days']) . ')</td>' . ($brk ? '<td class="n">' . $e(self::hm(array_sum(array_column($sheet['days'], 'break')))) . '</td>' : '') . '<td class="n">' . $e(self::hm($sheet['total_minutes'])) . '</td><td class="n">' . $e(self::decimal($sheet['total_minutes'], $lang)) . '</td></tr></tfoot></table>'
            . $round . ($signature ? '<div class="sign"><div>' . $e($t['sign']) . '</div><div>' . $e($t['made']) . ' ' . $e((string) ($head['made'] ?? '')) . '</div></div>' : '') . '</body></html>';
    }
}
