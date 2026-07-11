<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkLog;

/**
 * Tool: produce a CSV download of the work log for a period (date, job, hours,
 * what was done). Returns a link; the endpoint streams the file.
 */
final class ExportWorkLog implements Tool
{
    public function __construct(private WorkLog $log)
    {
    }

    public function name(): string
    {
        return 'export_work_log';
    }

    public function description(): string
    {
        return 'Creates a CSV download of the user\'s work log for a period (columns: date, job, hours, '
            . 'description). Returns a link — present it as a clickable download link. Use for "export my '
            . 'work log", "download what I did at DSB this month".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => [
                    'type'        => 'string',
                    'enum'        => ['this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'all'],
                    'description' => 'Which period. Defaults to this_month. Ignored if from/to given.',
                ],
                'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (overrides period).'],
                'to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (overrides period).'],
                'job'  => ['type' => 'string', 'description' => 'Limit to one job (e.g. DTU, DSB).'],
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
        $job   = trim((string) ($arguments['job'] ?? '')) ?: null;
        $count = $this->log->summary($userId, $from, $to, $job)['count'];

        $query = array_filter([
            'from' => $from,
            'to'   => $to,
            'job'  => $job,
        ], static fn ($v): bool => $v !== null && $v !== '');
        $host = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';
        $url  = 'https://' . $host . '/api/work-log-export.php' . ($query !== [] ? '?' . http_build_query($query) : '');

        return ['period' => $label, 'count' => $count, 'download_url' => $url];
    }
}
