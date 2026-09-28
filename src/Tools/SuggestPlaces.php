<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Data\Places;
use App\Data\Timeline;

/** Tool: unnamed places the user keeps returning to, offered for naming on the places card. */
final class SuggestPlaces implements Tool
{
    public function __construct(private Timeline $timeline, private Places $places, private LocationPoints $points)
    {
    }

    public function name(): string
    {
        return 'suggest_places';
    }

    public function description(): string
    {
        return 'Finds places the user keeps returning to but hasn\'t named (from the last 60 days of tracking): '
            . 'visits, days, average stay and typical pattern (e.g. "weekdays, usually arriving ~08:05"). Opens the '
            . 'places card where each suggestion can be named with one tap. Use for "suggest places", "which places '
            . 'should I save", "foreslå steder". Describe them by pattern — you get no coordinates.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $sugg = $this->timeline->suggestions($userId);
        $card = Places::card($this->places->list($userId), null, PlacePosition::here($this->points, $userId));
        $card['suggestions'] = $sugg;
        $card['_persist_strip'][] = 'suggestions';

        return [
            'count'       => count($sugg),
            'suggestions' => array_map(static fn (array $s, int $i): array => ['n' => $i + 1] + array_diff_key($s, ['lat' => 1, 'lon' => 1]), $sugg, array_keys($sugg)),
            'note'        => $sugg === [] ? 'No repeated unnamed places yet (needs stays on at least 2 different days).' : null,
            '_render'     => $card,
        ];
    }
}
