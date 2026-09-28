<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Data\Places;

/** Tool: the user's saved places (names/types/sizes for the model; the map editor card for the user). */
final class ListPlaces implements Tool
{
    public function __construct(private Places $places, private LocationPoints $points)
    {
    }

    public function name(): string
    {
        return 'list_places';
    }

    public function description(): string
    {
        return 'Lists the user\'s saved places (name, type, circle radius or polygon) and opens the places map card, '
            . 'where they can add a place by tapping the map, resize a circle, draw a polygon, or delete one. Use '
            . 'for "my places", "show/edit my places", "mine steder", or when they want to draw a place.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $all = $this->places->list($userId);

        return [
            'count'   => count($all),
            'places'  => array_map([Places::class, 'forModel'], $all),
            '_render' => Places::card($all, null, PlacePosition::here($this->points, $userId)),
        ];
    }
}
