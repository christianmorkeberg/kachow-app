<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Data\Timeline;
use App\Data\WorkEvents;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: one day of the user's tracked movement — a map card with the route and timeline, plus
 * for the model: the timeline (stays and trips, by place name), time per saved place, and
 * data-quality statistics. The model never receives coordinates; they go to the card only.
 */
final class GetLocationDay implements Tool
{
    public function __construct(private Timeline $timeline)
    {
    }

    public function name(): string
    {
        return 'get_location_day';
    }

    public function description(): string
    {
        return 'The user\'s day from location tracking (OwnTracks): a map card plus the TIMELINE — stays '
            . '(where they were, from–to, by saved place name or "unnamed place") and trips between them (km, '
            . 'minutes, mode walk/bike/vehicle — car and train look alike), time per saved place (in_places), '
            . 'and data-quality stats (points, interval, gaps, accuracy, battery). Use for "what did I do '
            . 'today/on Tuesday", "when did I get to / leave the office", "show my route", "hvor var jeg i går", '
            . 'or checking tracking. A stay counts from ~10 min in one spot (tunable in settings); gaps while '
            . 'still count as being there. An ongoing stay has to=null. No points → offer '
            . 'get_location_tracking_setup.';
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

        $day = $this->timeline->day($userId, $date);
        $out = $day['stats'];
        if (($out['points'] ?? 0) === 0) {
            $out['note'] = 'No location points for this day. Raw points are kept '
                . LocationPoints::RETENTION_DAYS . ' days; if tracking was never set up, offer get_location_tracking_setup.';
        }
        $out['_render'] = $day['card'];

        return $out;
    }
}
