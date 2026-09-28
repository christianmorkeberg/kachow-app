<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Data\Places;
use App\Maps\MapDistance;
use InvalidArgumentException;

/**
 * Tool: save a named place — at the user's current tracked position ("mark where I am as
 * Office") or at an address — as a circle. Polygons are drawn on the map card.
 */
final class SavePlace implements Tool
{
    public function __construct(private Places $places, private LocationPoints $points, private MapDistance $maps)
    {
    }

    public function name(): string
    {
        return 'save_place';
    }

    public function description(): string
    {
        return 'Saves a named place for location tracking, as a circle at the user\'s CURRENT tracked position '
            . '(default: "mark where I am as Office", "gem stedet her som Arbejde") or at an address. type: work '
            . '(a workplace — will drive the automatic work clock), business (client/business trips), commute, '
            . 'home, private or other. radius_m defaults to 100 (25–2000; ~150–300 suits a large workplace). '
            . 'Shows the places map card, where the user can fine-tune the circle or draw a polygon. Ask for the '
            . 'type if it isn\'t clear. Never invent coordinates.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name'     => ['type' => 'string', 'description' => 'What the user calls the place, e.g. "Office".'],
                'type'     => ['type' => 'string', 'enum' => Places::TYPES],
                'radius_m' => ['type' => 'integer', 'description' => 'Circle radius in metres (default 100).'],
                'address'  => ['type' => 'string', 'description' => 'Save at this address instead of the current position.'],
            ],
            'required' => ['name', 'type'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $pos = PlacePosition::resolve($this->points, $this->maps, $userId, $arguments['address'] ?? null);
        if (isset($pos['error'])) {
            return $pos;
        }
        try {
            $place = $this->places->add($userId, [
                'name'     => $arguments['name'] ?? '',
                'type'     => $arguments['type'] ?? 'other',
                'lat'      => $pos['lat'],
                'lon'      => $pos['lon'],
                'radius_m' => $arguments['radius_m'] ?? 100,
            ]);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
        $all = $this->places->list($userId);

        return [
            'saved'    => true,
            'place'    => Places::forModel($place),
            'position' => $pos['from'],
            'places'   => array_map([Places::class, 'forModel'], $all),
            '_render'  => Places::card($all, $place['id'], PlacePosition::here($this->points, $userId)),
        ];
    }
}
