<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Data\Timeline;

/** Tool: stays across a date range (optionally at one place) — visits, days, time per place. */
final class GetStays implements Tool
{
    public function __construct(private Timeline $timeline)
    {
    }

    public function name(): string
    {
        return 'get_stays';
    }

    public function description(): string
    {
        return 'Where the user stayed over a date range, from location tracking: per-place totals (visits, '
            . 'days, minutes) and each stay (day, from–to, minutes). Pass place to count one saved place ("how '
            . 'many times was I at the gym in October", "when did I leave the office each day this week", '
            . '"hvor meget tid brugte jeg hjemme"). Up to 62 days per call; raw data is kept 60 days. For a '
            . 'single day with a map, get_location_day is better.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'from'  => ['type' => 'string', 'description' => 'First local date YYYY-MM-DD.'],
                'to'    => ['type' => 'string', 'description' => 'Last local date YYYY-MM-DD (inclusive).'],
                'place' => ['type' => 'string', 'description' => 'Only stays at this saved place (its name).'],
            ],
            'required' => ['from', 'to'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        foreach (['from', 'to'] as $k) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($arguments[$k] ?? ''))) {
                return ['error' => '"' . $k . '" must be a date YYYY-MM-DD.'];
            }
        }
        $r = $this->timeline->stays($userId, $arguments['from'], $arguments['to'], $arguments['place'] ?? null);
        if ($r['stays'] === []) {
            $r['note'] = 'No stays found' . (!empty($arguments['place']) ? ' at "' . $arguments['place'] . '"' : '')
                . '. Raw points are kept ' . LocationPoints::RETENTION_DAYS . ' days; list_places shows saved names.';
        }

        return $r;
    }
}
