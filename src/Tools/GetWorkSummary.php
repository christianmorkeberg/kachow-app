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
        return 'Draws a bar chart of worked hours (from the clock) over ANY period, bucketed per day, week '
            . 'or month, optionally for one workplace, with bars STACKED per workplace when there are '
            . 'several. Presets: "week" (daily bars, current week), "4w"/"12w" (weekly), "year" (monthly). '
            . 'For anything else pass from/to (YYYY-MM-DD, inclusive) and optionally bucket (day/week/'
            . 'month; auto if omitted) — e.g. "this month per day", "since August per week", "hours at the '
            . 'office this month", "office vs client per week", Danish "hvor meget har jeg arbejdet hos X denne måned", '
            . '"vis mine timer per uge siden august". place matches by prefix: "Office" covers every site '
            . 'labelled "Office …" ("Office North", "Office South"). This is the DEFAULT for any whole-week or multi-day hours question. '
            . 'The result gives total, per-bucket and per-workplace totals (by_place) — answer from those '
            . 'in ONE call; never tell the user a split or period is impossible. Summarise (total, average, '
            . 'busiest) rather than reading every bar. For a single day, clock status or individual session '
            . 'times use get_work_hours.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => [
                    'type'        => 'string',
                    'enum'        => WorkEvents::CHART_MODES,
                    'description' => 'Preset: "week" (daily bars, current week), "4w" or "12w" (weekly '
                        . 'bars), "year" (monthly bars). Default "week". Ignored when from/to are given.',
                ],
                'from'   => ['type' => 'string', 'description' => 'Custom range start, YYYY-MM-DD (inclusive). Use with "to".'],
                'to'     => ['type' => 'string', 'description' => 'Custom range end, YYYY-MM-DD (inclusive; "until today" = today).'],
                'bucket' => [
                    'type'        => 'string',
                    'enum'        => ['day', 'week', 'month'],
                    'description' => 'Bar size for a custom range. Omit for automatic.',
                ],
                'place'  => ['type' => 'string', 'description' => 'Only this workplace (prefix match: "Office" = "Office North", "Office South", …). Omit for all, stacked per place.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $period = isset($arguments['period']) && $arguments['period'] !== ''
            ? (string) $arguments['period'] : 'week';

        $from   = trim((string) ($arguments['from'] ?? ''));
        $to     = trim((string) ($arguments['to'] ?? ''));
        if ($from !== '' && $to === '') {
            $to = (new \DateTimeImmutable('now', new \DateTimeZone(WorkEvents::LOCAL_TZ)))->format('Y-m-d');
        }
        foreach ([$from, $to] as $d) {
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return ['error' => 'Dates must be YYYY-MM-DD (got "' . $d . '").'];
            }
        }
        $place = isset($arguments['place']) ? trim((string) $arguments['place']) : null;

        $card = $this->events->breakdown(
            $userId,
            $period,
            $from !== '' ? $from : null,
            $to !== '' ? $to : null,
            isset($arguments['bucket']) ? (string) $arguments['bucket'] : null,
            $place,
        );
        $placesAll = $card['places_all'];
        unset($card['places_all']);

        // Compact bucket list + busiest bucket for the model to talk about.
        $byBucket = [];
        $busiest  = null;
        foreach ($card['bars'] as $b) {
            $byBucket[$b['label']] = $b['total'];
            if ($b['minutes'] > 0 && ($busiest === null || $b['minutes'] > $busiest['minutes'])) {
                $busiest = $b;
            }
        }

        $result = [
            'range'         => $card['range'],
            'total'         => $card['total'],
            'total_minutes' => $card['total_minutes'],
            'average'       => $card['avg'] . ' per ' . $card['bucket_word'],
            'busiest'       => $busiest !== null
                ? ($busiest['label'] . ' (' . $busiest['total'] . ')')
                : null,
            'by_bucket'     => $byBucket,
            'by_place'      => array_map(static fn (array $p): array => ['place' => $p['place'], 'total' => $p['total']], $placesAll),
            'has_data'      => $card['has_data'],
            '_render'       => $card,
        ];
        if (!$card['has_data'] && $place !== null && $place !== '') {
            $result['known_places'] = $this->events->knownPlaces($userId);
            $result['hint'] = 'No hours matched that workplace — check known_places and retry with a real label.';
        }

        return $result;
    }
}
