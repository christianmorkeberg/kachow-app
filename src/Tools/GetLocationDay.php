<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Data\WorkEvents;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: one day of the user's tracked movement — a map card with the route, plus data-quality
 * statistics for the model (point count, time span, distance, largest gap, accuracy, battery).
 * The model never receives coordinates; they go to the map card only.
 */
final class GetLocationDay implements Tool
{
    public function __construct(private LocationPoints $points)
    {
    }

    public function name(): string
    {
        return 'get_location_day';
    }

    public function description(): string
    {
        return 'Shows one day of the user\'s tracked location (OwnTracks) on a map card, and returns that '
            . 'day\'s statistics: number of points, first/last time, distance (km, from accurate fixes), '
            . 'average interval, largest gap between points, median accuracy, battery. Use for "show my '
            . 'route today", "where have I been", "vis min dag på kortet", or checking tracking quality. A '
            . 'gap usually means the phone was still (iOS pauses updates), not that tracking failed. With '
            . 'no points, suggest get_location_tracking_setup. Places/stays are not analysed yet.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'Local date YYYY-MM-DD. Defaults to today.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $date = trim((string) ($arguments['date'] ?? ''));
        if ($date === '') {
            $date = (new DateTimeImmutable('now', new DateTimeZone(WorkEvents::LOCAL_TZ)))->format('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['error' => 'Date must be YYYY-MM-DD (got "' . $date . '").'];
        }

        $day = $this->points->day($userId, $date);
        $out = $day['stats'];
        if (($out['points'] ?? 0) === 0) {
            $out['note'] = 'No location points for this day. Raw points are kept '
                . LocationPoints::RETENTION_DAYS . ' days; if tracking was never set up, offer get_location_tracking_setup.';
        }
        $out['_render'] = $day['card'];

        return $out;
    }
}
