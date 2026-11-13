<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkLog;

/**
 * Tool: review the work log for a period (per-job hours + what was done). Renders
 * a card, so the reply should be a brief summary, not a re-list of every entry.
 */
final class GetWorkLog implements Tool
{
    public function __construct(private WorkLog $log)
    {
    }

    public function name(): string
    {
        return 'get_work_log';
    }

    public function description(): string
    {
        return 'Shows the user\'s work log for a period — WHAT they did at each job (workplace), as a '
            . 'list of task notes. Use for "what did I do at work this week", "my work log last month". '
            . 'This does NOT report hours worked — for how much/how long they worked (today, this week, '
            . 'last week, per day/month) use get_work_hours or get_work_summary (the clock). Renders a '
            . 'card, so give a short summary of the tasks rather than listing every entry.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => [
                    'type'        => 'string',
                    'enum'        => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'all'],
                    'description' => 'Which period. Defaults to this_week. Ignored if from/to given.',
                ],
                'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (overrides period).'],
                'to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (overrides period).'],
                'job'  => ['type' => 'string', 'description' => 'Limit to one job (the workplace name). ALWAYS pass it when the user names or focuses on a workplace ("for DTU", "at DSB") — otherwise the card shows every job.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        [$from, $to, $label] = WorkLog::resolveRange(
            (string) ($arguments['period'] ?? 'this_week'),
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null
        );
        $job = trim((string) ($arguments['job'] ?? '')) ?: null;

        $summary = $this->log->summary($userId, $from, $to, $job);
        $title   = 'Work log · ' . $label . ($job !== null ? ' · ' . $job : '');

        return [
            'count'   => $summary['count'],
            'by_job'  => $summary['by_job'],
            'items'   => $summary['items'],
            'job_filter' => $job,
            'note'    => ($job !== null && $summary['count'] === 0 ? 'Nothing is logged for "' . $job . '" in this period — say so; do not list other jobs\' entries. ' : '')
                . 'This is what the user DID at work (tasks), not hours. If they asked how much/'
                . 'how long they worked, use get_work_hours or get_work_summary instead.',
            // This/last week with no narrowing: the paging card (arrows + job filter) instead of a fixed snapshot.
            '_render' => $job === null && empty($arguments['from']) && in_array($arguments['period'] ?? 'this_week', ['this_week', 'last_week'], true)
                ? $this->log->weekCard($userId, ($arguments['period'] ?? 'this_week') === 'last_week' ? -1 : 0)
                : $this->log->card($userId, $from, $to, $title, $job),
        ];
    }
}
