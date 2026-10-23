<?php

declare(strict_types=1);

namespace App\Tools;

use App\Support\DataExport;
use App\Support\Timesheet;

/**
 * Tool: export what one of the read tools returns as a file the user can send on — a CSV, or a printable page (save as PDF). The tool names
 * the read tool and its arguments; this runs it once (to check there is something and say what), and api/export.php runs it again when the
 * link is opened, so nothing is stored. layout "timesheet" turns work-clock sessions into hours per day with a total, for a customer.
 */
final class ExportData implements Tool
{
    /** The read tools whose results may be exported. */
    public const SOURCES = [
        'get_work_hours', 'get_work_log', 'get_expenses', 'get_income', 'get_mileage', 'get_trips', 'get_stays', 'get_workout_history',
        'get_calendar_events', 'get_vinyls', 'get_books', 'get_wishlist', 'get_shopping_list', 'get_owner_draws', 'get_moms', 'get_cash',
    ];

    private ?ToolRegistry $registry = null;

    public function setRegistry(ToolRegistry $registry): void
    {
        $this->registry = $registry;
    }

    public function name(): string
    {
        return 'export_data';
    }

    public function description(): string
    {
        return 'Exports the data one of the read tools returns as a download the user can send on: a CSV (spreadsheet) and a printable page (open it, '
            . 'then print / save as PDF). Say WHICH read tool (source) and the SAME arguments you would give it to fetch that data (args — dates, '
            . 'place, category …). Use for "export / download / send me / make a file of …" for work hours, the work log, expenses, income, mileage, trips, '
            . 'workouts, calendar, vinyls, books, wishlist, shopping list. For a TIMESHEET ("timeseddel", hours per day for a customer) use source '
            . 'get_work_hours with layout "timesheet" (place = the workplace / customer; break_minutes when a lunch break was deducted on the invoice; round_minutes only if asked). Returns the links and what '
            . 'is in the file — present both links plus a one-line summary. Sources: ' . implode(', ', self::SOURCES) . '.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'source'        => ['type' => 'string', 'enum' => self::SOURCES, 'description' => 'The read tool whose result to export.'],
                'args'          => ['type' => 'object', 'description' => 'The arguments to call that tool with (e.g. {"from":"2026-10-01","to":"2026-10-31","place":"Office"}).'],
                'layout'        => ['type' => 'string', 'enum' => ['table', 'timesheet'], 'description' => 'table (default: one row per record) or timesheet (get_work_hours only: hours per day + total).'],
                'columns'       => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these columns, in this order (table layout). Default: all.'],
                'title'         => ['type' => 'string', 'description' => 'Heading on the printable page.'],
                'customer'      => ['type' => 'string', 'description' => 'Customer name on the printed timesheet.'],
                'round_minutes' => ['type' => 'integer', 'description' => 'Timesheet only: round each day to the nearest N minutes — 5, 10, 15 or 30 (0 = exact, the default).'],
                'break_minutes' => ['type' => 'integer', 'description' => 'Timesheet only: lunch break to deduct from each day with more than break_after_hours of work (e.g. 30). Use when the user says a break was deducted on the invoice.'],
                'break_after_hours' => ['type' => 'number', 'description' => 'Timesheet only: the break is deducted only on days with MORE than this many hours worked. Default 4.'],
                'language'      => ['type' => 'string', 'enum' => ['da', 'en'], 'description' => 'Language of a timesheet. Default da.'],
            ],
            'required' => ['source'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $source = (string) ($arguments['source'] ?? '');
        if (!in_array($source, self::SOURCES, true)) {
            return ['error' => 'source must be one of: ' . implode(', ', self::SOURCES)];
        }
        $args = is_array($arguments['args'] ?? null) ? $arguments['args'] : [];
        $spec = [
            'source'   => $source,
            'args'     => $args,
            'layout'   => ($arguments['layout'] ?? 'table') === 'timesheet' ? 'timesheet' : 'table',
            'columns'  => array_values(array_filter(array_map('strval', (array) ($arguments['columns'] ?? [])), static fn (string $c): bool => $c !== '')),
            'title'    => trim((string) ($arguments['title'] ?? '')),
            'customer' => trim((string) ($arguments['customer'] ?? '')),
            'round'    => (int) ($arguments['round_minutes'] ?? 0),
            'break'    => max(0, (int) ($arguments['break_minutes'] ?? 0)),
            'break_over' => (int) round(max(0.0, (float) ($arguments['break_after_hours'] ?? 4)) * 60),
            'lang'     => ($arguments['language'] ?? 'da') === 'en' ? 'en' : 'da',
        ];
        if ($spec['layout'] === 'timesheet' && $source !== 'get_work_hours') {
            return ['error' => 'the timesheet layout needs source get_work_hours'];
        }
        if ($this->registry === null) {
            return ['error' => 'export is not available here'];
        }

        $built = self::build($this->registry, $spec, $userId);
        if (isset($built['error'])) {
            return ['error' => $built['error']];
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';
        $base = 'https://' . $host . '/api/export.php?spec=' . rawurlencode(json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['source' => $source, 'layout' => $spec['layout'], 'summary' => $built['summary'], 'csv_url' => $base . '&format=csv', 'print_url' => $base . '&format=html'];
    }

    /**
     * Runs the source and shapes it; shared by the tool (a preview) and api/export.php (the file).
     *
     * @param array<string, mixed> $spec
     * @return array{error?:string, summary?:array<string,mixed>, rows?:list<list<string>>, columns?:list<string>, sheet?:array<string,mixed>, needs_fix?:array<int,mixed>}
     */
    public static function build(ToolRegistry $registry, array $spec, int $userId): array
    {
        if (!in_array($spec['source'] ?? '', self::SOURCES, true)) {
            return ['error' => 'unknown source'];
        }
        $result = $registry->dispatch((string) $spec['source'], is_array($spec['args'] ?? null) ? $spec['args'] : [], $userId);
        if (isset($result['error'])) {
            return ['error' => (string) $result['error']];
        }

        if (($spec['layout'] ?? 'table') === 'timesheet') {
            $sheet = Timesheet::build(is_array($result['sessions'] ?? null) ? $result['sessions'] : [], (int) ($spec['round'] ?? 0), [], (int) ($spec['break'] ?? 0), (int) ($spec['break_over'] ?? 240));
            if ($sheet['days'] === []) {
                return ['error' => 'no clocked hours in that period'];
            }

            return [
                'sheet'     => $sheet,
                'needs_fix' => $result['needs_fix'] ?? [],
                'summary'   => [
                    'days' => count($sheet['days']), 'total' => Timesheet::hm($sheet['total_minutes']), 'total_decimal' => Timesheet::decimal($sheet['total_minutes'], (string) ($spec['lang'] ?? 'da')),
                    'rounded_to' => $sheet['round'], 'break_deducted_days' => count(array_filter($sheet['days'], static fn (array $d): bool => $d['break'] > 0)), 'needs_fix' => $result['needs_fix'] ?? [],
                ],
            ];
        }

        $table = DataExport::table($result, null, $spec['columns'] ?? null);
        if ($table === null || $table['rows'] === []) {
            return ['error' => 'nothing to export for that request'];
        }

        return ['columns' => $table['columns'], 'rows' => $table['rows'], 'summary' => ['rows' => count($table['rows']), 'columns' => $table['columns']]];
    }
}
