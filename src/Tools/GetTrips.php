<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Timeline;

/** Tool: trips (movement between stays) across a date range. */
final class GetTrips implements Tool
{
    public function __construct(private Timeline $timeline)
    {
    }

    public function name(): string
    {
        return 'get_trips';
    }

    public function description(): string
    {
        return 'The user\'s trips over a date range, from location tracking: each trip\'s day, from/to (saved '
            . 'place names or "unnamed place"), start/end time, minutes, km along the tracked path, and a mode '
            . 'guess (walk / bike / vehicle — car and train look alike). Optional mode filter. For "which drives '
            . 'did I make this week", "how far did I walk". GPS km are for insight only — kørsel/SKAT distances '
            . 'still come from get_driving_distance / the mileage destinations. Up to 62 days per call.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'First local date YYYY-MM-DD.'],
                'to'   => ['type' => 'string', 'description' => 'Last local date YYYY-MM-DD (inclusive).'],
                'mode' => ['type' => 'string', 'enum' => ['walk', 'bike', 'vehicle']],
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

        return $this->timeline->trips($userId, $arguments['from'], $arguments['to'], $arguments['mode'] ?? null);
    }
}
