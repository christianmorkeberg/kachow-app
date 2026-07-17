<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkEvents;

/**
 * Tool: chart worked hours over a period as a bar chart — daily bars for the current
 * week, weekly bars for the last 4/12 weeks, or monthly bars for the last year. Use
 * when the user wants the DISTRIBUTION or TREND of their hours rather than a single
 * total ("how many hours did I work this month?", "show my work hours per day this
 * week", "have my hours gone up over the last months?", Danish "hvor mange timer har
 * jeg arbejdet denne måned?", "vis mine arbejdstimer per uge"). For a single-day or
 * this-week TOTAL with the individual sessions, use get_work_hours instead.
 */
final class GetWorkSummary implements Tool
{
    public function __construct(private WorkEvents $events)
    {
    }

    public function name(): string
    {
        return 'get_work_summary';
    }

    public function description(): string
    {
        return 'Shows a bar chart of worked hours over a period: daily bars for this week, weekly bars '
            . 'for the last 4 or 12 weeks, or monthly bars for the last year. Use for the trend / '
            . 'distribution of hours ("hours per day this week", "how much did I work each month", "are '
            . 'my hours increasing", Danish "arbejdstimer per uge/måned"). The card carries the bars, so '
            . 'summarise (total, average per ' . 'day/week/month, busiest period) rather than reading '
            . 'every bar. For a single total plus the sessions, use get_work_hours.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => [
                    'type'        => 'string',
                    'enum'        => WorkEvents::CHART_MODES,
                    'description' => 'Bucketing: "week" (daily bars, current week), "4w" or "12w" (weekly '
                        . 'bars), "year" (monthly bars). Default "week".',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $period = isset($arguments['period']) && $arguments['period'] !== ''
            ? (string) $arguments['period'] : 'week';

        $card = $this->events->breakdown($userId, $period);

        // Compact bucket list + busiest bucket for the model to talk about.
        $byBucket = [];
        $busiest  = null;
        foreach ($card['bars'] as $b) {
            $byBucket[$b['label']] = $b['total'];
            if ($b['minutes'] > 0 && ($busiest === null || $b['minutes'] > $busiest['minutes'])) {
                $busiest = $b;
            }
        }

        return [
            'range'         => $card['range'],
            'total'         => $card['total'],
            'total_minutes' => $card['total_minutes'],
            'average'       => $card['avg'] . ' per ' . $card['bucket_word'],
            'busiest'       => $busiest !== null
                ? ($busiest['label'] . ' (' . $busiest['total'] . ')')
                : null,
            'by_bucket'     => $byBucket,
            'has_data'      => $card['has_data'],
            '_render'       => $card,
        ];
    }
}
