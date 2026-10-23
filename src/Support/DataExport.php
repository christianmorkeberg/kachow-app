<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turns the result of any read tool into a table for export: finds the list of records in it, flattens each to scalar columns, and renders
 * a CSV row set or a printable page. Knows nothing about what the data means — the tool that produced it did the work. Pure.
 */
final class DataExport
{
    public const MAX_ROWS = 5000;

    /**
     * @param array<string, mixed> $result a tool's result
     * @param list<string>|null $columns keep / order only these columns
     * @return array{key:string, columns:list<string>, rows:list<list<string>>}|null null when the result holds no list of records
     */
    public static function table(array $result, ?string $listKey = null, ?array $columns = null): ?array
    {
        $key = $listKey !== null && self::isRecords($result[$listKey] ?? null) ? $listKey : self::bestList($result);
        if ($key === null) {
            return null;
        }
        $records = array_slice(array_values($result[$key]), 0, self::MAX_ROWS);
        $cols    = [];
        foreach ($records as $r) {
            foreach ($r as $k => $v) {
                if (is_string($k) && $k[0] !== '_' && (is_scalar($v) || $v === null) && !in_array($k, $cols, true)) {
                    $cols[] = $k;
                }
            }
        }
        if ($columns !== null && $columns !== []) {
            $cols = array_values(array_filter($columns, static fn (string $c): bool => in_array($c, $cols, true)));
        }
        $rows = [];
        foreach ($records as $r) {
            $rows[] = array_map(static fn (string $c): string => self::cell($r[$c] ?? null), $cols);
        }

        return ['key' => $key, 'columns' => $cols, 'rows' => $rows];
    }

    /** Name of the list-of-records entry with the most records (ties: first), or null. */
    public static function bestList(array $result): ?string
    {
        $best = null;
        $n    = 0;
        foreach ($result as $k => $v) {
            if (is_string($k) && $k[0] !== '_' && self::isRecords($v) && count($v) > $n) {
                $best = $k;
                $n    = count($v);
            }
        }

        return $best;
    }

    /** A non-empty list whose items are associative arrays. */
    private static function isRecords(mixed $v): bool
    {
        if (!is_array($v) || $v === [] || !array_is_list($v)) {
            return false;
        }
        foreach ($v as $r) {
            if (!is_array($r) || array_is_list($r)) {
                return false;
            }
        }

        return true;
    }

    private static function cell(mixed $v): string
    {
        return match (true) {
            $v === null  => '',
            is_bool($v)  => $v ? '1' : '0',
            default      => (string) $v,
        };
    }

    private const WORDS = [
        'da' => ['total' => 'I alt', 'count' => 'Antal', 'print' => 'Udskriv / gem som PDF'],
        'en' => ['total' => 'Total', 'count' => 'Count', 'print' => 'Print / save as PDF'],
    ];

    /** A number for a cell: two decimals at most, no trailing zeros, decimal comma for Danish. */
    public static function num(float $x, string $lang): string
    {
        $dec = $lang === 'da' ? ',' : '.';
        $s   = number_format($x, 2, $dec, '');

        return str_contains($s, $dec) ? rtrim(rtrim($s, '0'), $dec) : $s;
    }

    /**
     * Shapes a table the way the user asked: filter (where), calculated columns (calc), sort, group with sums, a total row, renamed headings,
     * a row limit. Every step is optional; with no options the table comes back as it was.
     *
     * @param array{columns:list<string>, rows:list<list<string>>} $table table()
     * @param array<string, mixed> $o where:{col:value}, calc:[{label,column,divide?,multiply?}], sort_by, sort_desc, group_by, group_period (day|week|month),
     *                                total_columns:list<string>, labels:{col:label}, limit, total (false = never a total row)
     * @return array{columns:list<string>, labels:list<string>, rows:list<list<string>>, total:?list<string>}
     */
    public static function shape(array $table, array $o = [], string $lang = 'da'): array
    {
        $lang   = isset(self::WORDS[$lang]) ? $lang : 'da';
        $cols   = $table['columns'];
        $names  = array_combine($cols, array_map([self::class, 'label'], $cols));
        $recs   = array_map(static fn (array $r): array => array_combine($cols, $r), $table['rows']);
        $calc   = [];

        foreach ((array) ($o['where'] ?? []) as $c => $v) {
            if (in_array($c, $cols, true) && is_scalar($v)) {
                $recs = array_values(array_filter($recs, static fn (array $r): bool => mb_strtolower($r[$c]) === mb_strtolower((string) $v)));
            }
        }

        foreach (array_values((array) ($o['calc'] ?? [])) as $i => $c) {
            $from = is_array($c) ? (string) ($c['column'] ?? '') : '';
            if (!in_array($from, $cols, true)) {
                continue;
            }
            $key = 'calc_' . ($i + 1);
            $div = (float) ($c['divide'] ?? 0);
            $mul = isset($c['multiply']) ? (float) $c['multiply'] : 1.0;
            foreach ($recs as &$r) {
                $n = is_numeric(str_replace(',', '.', $r[$from])) ? (float) str_replace(',', '.', $r[$from]) : 0.0;
                $r[$key] = (string) round(($div != 0.0 ? $n / $div : $n) * $mul, 4);
            }
            unset($r);
            $cols[]       = $key;
            $calc[$key]   = true;
            $names[$key]  = (string) (($c['label'] ?? '') !== '' ? $c['label'] : self::label($from));
        }

        $by = (string) ($o['sort_by'] ?? '');
        if ($by !== '' && in_array($by, $cols, true)) {
            $desc = !empty($o['sort_desc']);
            usort($recs, static function (array $a, array $b) use ($by, $desc): int {
                $x = $a[$by];
                $y = $b[$by];
                $c = is_numeric($x) && is_numeric($y) ? (float) $x <=> (float) $y : strcmp(mb_strtolower($x), mb_strtolower($y));

                return $desc ? -$c : $c;
            });
        }

        $sums = array_values(array_filter((array) ($o['total_columns'] ?? []), static fn ($c): bool => is_string($c) && in_array($c, $cols, true)));
        $sum  = static function (array $set, string $c): float {
            $t = 0.0;
            foreach ($set as $r) {
                $v = str_replace(',', '.', (string) ($r[$c] ?? ''));
                $t += is_numeric($v) ? (float) $v : 0.0;
            }

            return $t;
        };
        $cell = static fn (string $c, float $x): string => self::num($x, $lang);

        $group = (string) ($o['group_by'] ?? '');
        if ($group !== '' && in_array($group, $cols, true)) {
            $period = (string) ($o['group_period'] ?? '');
            $bucket = [];
            foreach ($recs as $r) {
                $k = $r[$group];
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $k, $m) === 1) {
                    $k = match ($period) {
                        'month' => $m[1] . '-' . $m[2],
                        'week'  => (new \DateTimeImmutable($m[0]))->format('o-\WW'),
                        default => $m[0],
                    };
                }
                $bucket[$k][] = $r;
            }
            ksort($bucket);
            $rows = [];
            foreach ($bucket as $k => $set) {
                $rows[] = array_merge([(string) $k, (string) count($set)], array_map(static fn (string $c): string => $cell($c, $sum($set, $c)), $sums));
            }
            $outCols = array_merge([$group, 'count'], $sums);
            $names['count'] = self::WORDS[$lang]['count'];
            $total = $sums !== [] || ($o['total'] ?? false)
                ? array_merge([self::WORDS[$lang]['total'], (string) count($recs)], array_map(static fn (string $c): string => $cell($c, $sum($recs, $c)), $sums))
                : null;
        } else {
            $outCols = $cols;
            $rows    = array_map(static fn (array $r): array => array_map(
                static fn (string $c): string => isset($calc[$c]) ? self::num((float) $r[$c], $lang) : $r[$c],
                $outCols
            ), $recs);
            $total = null;
            if ($sums !== []) {
                $total = array_map(static fn (string $c): string => in_array($c, $sums, true) ? $cell($c, $sum($recs, $c)) : '', $outCols);
                $first = array_search(true, array_map(static fn (string $c): bool => !in_array($c, $sums, true), $outCols), true);
                if ($first !== false) {
                    $total[$first] = self::WORDS[$lang]['total'];
                }
            }
        }
        if (($o['total'] ?? true) === false) {
            $total = null;
        }
        $limit = (int) ($o['limit'] ?? 0);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }
        $over = is_array($o['labels'] ?? null) ? $o['labels'] : [];

        return [
            'columns' => $outCols,
            'labels'  => array_map(static fn (string $c): string => isset($over[$c]) && $over[$c] !== '' ? (string) $over[$c] : ($names[$c] ?? self::label($c)), $outCols),
            'rows'    => $rows,
            'total'   => $total,
        ];
    }

    /** "total_minutes" → "Total minutes" */
    public static function label(string $column): string
    {
        return ucfirst(str_replace('_', ' ', $column));
    }

    /**
     * A standalone printable page (black on white; "save as PDF" from the browser).
     *
     * @param array{columns:list<string>, rows:list<list<string>>, labels?:list<string>, total?:?list<string>} $table shape() (labels, total optional)
     * @param array<string, string> $meta label → value lines above the table
     */
    public static function html(string $title, array $table, array $meta = [], string $footer = '', string $lang = 'da'): string
    {
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $m    = '';
        foreach ($meta as $k => $v) {
            if ($v !== '') {
                $m .= '<div><span>' . $e($k) . '</span> ' . $e($v) . '</div>';
            }
        }
        $head = '';
        foreach ($table['columns'] as $i => $c) {
            $head .= '<th>' . $e($table['labels'][$i] ?? self::label($c)) . '</th>';
        }
        $body = '';
        foreach ($table['rows'] as $row) {
            $body .= '<tr>' . implode('', array_map(static fn (string $c): string => '<td>' . $e($c) . '</td>', $row)) . '</tr>';
        }

        $foot = isset($table['total']) && $table['total'] !== null
            ? '<tfoot><tr>' . implode('', array_map(static fn (string $c): string => '<td>' . $e($c) . '</td>', $table['total'])) . '</tr></tfoot>'
            : '';
        $note = $footer !== '' ? '<p class="note">' . nl2br($e($footer)) . '</p>' : '';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($title) . '</title><style>'
            . 'body{font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#111;background:#fff;margin:0;padding:28px}'
            . 'h1{font-size:22px;margin:0 0 10px}.meta{margin-bottom:16px}.meta span{color:#666;display:inline-block;min-width:92px}'
            . 'table{border-collapse:collapse;width:100%}th,td{padding:6px 9px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}'
            . 'th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#555;border-bottom:2px solid #111}'
            . 'tfoot td{font-weight:700;border-top:2px solid #111;border-bottom:0}.note{color:#555;font-size:12px}'
            . '.bar{margin:0 0 18px}.bar button{font:inherit;padding:8px 14px;border:1px solid #111;background:#fff;border-radius:8px;cursor:pointer}'
            . '@media print{.bar{display:none}body{padding:0}}'
            . '</style></head><body><div class="bar"><button onclick="window.print()">' . $e(self::WORDS[$lang]['print'] ?? 'Print / save as PDF') . '</button></div><h1>' . $e($title) . '</h1><div class="meta">' . $m . '</div>'
            . '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody>' . $foot . '</table>' . $note . '</body></html>';
    }
}
