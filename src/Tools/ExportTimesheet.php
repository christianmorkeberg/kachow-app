<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkEvents;
use App\Data\WorkLog;
use App\Support\Timesheet;

/**
 * Tool: a timesheet (hours per day) for a period, to send to a customer — a CSV and a printable page (save as PDF). The figures are the work
 * clock's; the endpoint renders the files. Returns the facts and the links.
 */
final class ExportTimesheet implements Tool
{
    public function __construct(private WorkEvents $events, private WorkLog $log)
    {
    }

    public function name(): string
    {
        return 'export_timesheet';
    }

    public function description(): string
    {
        return 'Makes a TIMESHEET ("timeseddel") the user can send to a customer: the hours worked per DAY (times and hours, with a total) for a '
            . 'period, from the work clock — as a CSV download and a printable page (open it, then print / save as PDF). Returns the links, the days and '
            . 'the total; present both as clickable links plus a one-line summary. Use for "timeseddel", "timesheet", "hours per day for the client", '
            . '"send my hours to the customer". Set place to the workplace/customer when they name one. (export_work_log is the "what I did" notes without '
            . 'hours — not this.) Any clock-in that was never clocked out is left out and listed in needs_fix — mention it.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period'        => [
                    'type'        => 'string',
                    'enum'        => ['this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'all'],
                    'description' => 'Which period. Defaults to this_month. Ignored if from/to given.',
                ],
                'from'          => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (overrides period).'],
                'to'            => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (overrides period).'],
                'place'         => ['type' => 'string', 'description' => 'Only the hours at this workplace / customer.'],
                'customer'      => ['type' => 'string', 'description' => 'Customer name to print on the sheet (optional).'],
                'round_minutes' => ['type' => 'integer', 'enum' => Timesheet::ROUNDINGS, 'description' => 'Round each day to the nearest N minutes (0 = exact, the default). Only when the user asks.'],
                'include_notes' => ['type' => 'boolean', 'description' => 'Add a description column from the "what I did" log. Default false.'],
                'language'      => ['type' => 'string', 'enum' => ['da', 'en'], 'description' => 'Language of the sheet. Default: the language the user writes in.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        [$from, $to, $label] = WorkLog::resolveRange(
            (string) ($arguments['period'] ?? 'this_month'),
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null
        );
        $place = trim((string) ($arguments['place'] ?? '')) ?: null;
        $round = (int) ($arguments['round_minutes'] ?? 0);
        $notes = (bool) ($arguments['include_notes'] ?? false);
        $lang  = ($arguments['language'] ?? 'da') === 'en' ? 'en' : 'da';

        $built = Timesheet::forRange($this->events, $this->log, $userId, $from, $to, $place, $round, $notes);
        $sheet = $built['sheet'];

        $query = array_filter([
            'from'     => $from,
            'to'       => $to,
            'place'    => $place,
            'customer' => trim((string) ($arguments['customer'] ?? '')) ?: null,
            'round'    => $sheet['round'] > 0 ? $sheet['round'] : null,
            'notes'    => $notes ? 1 : null,
            'lang'     => $lang,
        ], static fn ($v): bool => $v !== null && $v !== '');
        $host = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';
        $base = 'https://' . $host . '/api/timesheet.php?' . http_build_query($query);

        return [
            'period'        => $label,
            'days'          => array_map(static fn (array $d): array => [
                'date' => $d['date'], 'times' => Timesheet::times($d, $lang), 'hours' => Timesheet::hm($d['counted']),
            ], $sheet['days']),
            'day_count'     => count($sheet['days']),
            'total'         => Timesheet::hm($sheet['total_minutes']),
            'total_decimal' => Timesheet::decimal($sheet['total_minutes'], $lang),
            'rounded_to'    => $sheet['round'],
            'needs_fix'     => $built['needs_fix'],
            'csv_url'       => $base . '&format=csv',
            'print_url'     => $base . '&format=html',
        ];
    }
}
