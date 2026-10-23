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

    /** "total_minutes" → "Total minutes" */
    public static function label(string $column): string
    {
        return ucfirst(str_replace('_', ' ', $column));
    }

    /**
     * A standalone printable page (black on white; "save as PDF" from the browser).
     *
     * @param array{columns:list<string>, rows:list<list<string>>} $table
     * @param array<string, string> $meta label → value lines above the table
     */
    public static function html(string $title, array $table, array $meta = []): string
    {
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $m    = '';
        foreach ($meta as $k => $v) {
            if ($v !== '') {
                $m .= '<div><span>' . $e($k) . '</span> ' . $e($v) . '</div>';
            }
        }
        $head = '';
        foreach ($table['columns'] as $c) {
            $head .= '<th>' . $e(self::label($c)) . '</th>';
        }
        $body = '';
        foreach ($table['rows'] as $row) {
            $body .= '<tr>' . implode('', array_map(static fn (string $c): string => '<td>' . $e($c) . '</td>', $row)) . '</tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($title) . '</title><style>'
            . 'body{font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#111;background:#fff;margin:0;padding:28px}'
            . 'h1{font-size:22px;margin:0 0 10px}.meta{margin-bottom:16px}.meta span{color:#666;display:inline-block;min-width:92px}'
            . 'table{border-collapse:collapse;width:100%}th,td{padding:6px 9px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}'
            . 'th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#555;border-bottom:2px solid #111}'
            . '.bar{margin:0 0 18px}.bar button{font:inherit;padding:8px 14px;border:1px solid #111;background:#fff;border-radius:8px;cursor:pointer}'
            . '@media print{.bar{display:none}body{padding:0}}'
            . '</style></head><body><div class="bar"><button onclick="window.print()">Print / save as PDF</button></div><h1>' . $e($title) . '</h1><div class="meta">' . $m . '</div>'
            . '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></body></html>';
    }
}
