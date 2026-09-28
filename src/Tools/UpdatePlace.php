<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Data\Places;
use App\Maps\MapDistance;
use InvalidArgumentException;

/** Tool: rename / retype / resize a saved place, or move it to the current position or an address. */
final class UpdatePlace implements Tool
{
    public function __construct(private Places $places, private LocationPoints $points, private MapDistance $maps)
    {
    }

    public function name(): string
    {
        return 'update_place';
    }

    public function description(): string
    {
        return 'Changes a saved place (found by its current name): new_name, type, radius_m (circles), or move it '
            . '— move_here=true puts the circle at the user\'s current tracked position, address= at an address. '
            . 'Only pass what changes.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'place'     => ['type' => 'string', 'description' => 'The place\'s current name.'],
                'new_name'  => ['type' => 'string'],
                'type'      => ['type' => 'string', 'enum' => Places::TYPES],
                'radius_m'  => ['type' => 'integer'],
                'move_here' => ['type' => 'boolean', 'description' => 'Move it to the user\'s current position.'],
                'address'   => ['type' => 'string', 'description' => 'Move it to this real street address (never "here"/"home" — use move_here).'],
            ],
            'required' => ['place'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $cur = $this->places->findByName($userId, (string) ($arguments['place'] ?? ''));
        if ($cur === null) {
            return ['error' => 'No place called "' . ($arguments['place'] ?? '') . '".',
                'places' => array_map([Places::class, 'forModel'], $this->places->list($userId))];
        }

        $data = [];
        if (!empty($arguments['new_name'])) {
            $data['name'] = $arguments['new_name'];
        }
        if (!empty($arguments['type'])) {
            $data['type'] = $arguments['type'];
        }
        if (isset($arguments['radius_m'])) {
            $data['radius_m'] = $arguments['radius_m'];
        }
        $position = null;
        if (!empty($arguments['move_here']) || !empty($arguments['address'])) {
            $pos = PlacePosition::resolve($this->points, $this->maps, $userId, $arguments['address'] ?? null);
            if (isset($pos['error'])) {
                return $pos;
            }
            $data['lat'] = $pos['lat'];
            $data['lon'] = $pos['lon'];
            $data['radius_m'] ??= $cur['radius_m'] ?? 100;
            $position = $pos['from'];
        }
        if ($data === []) {
            return ['error' => 'Nothing to change — pass new_name, type, radius_m, move_here or address.'];
        }
        if (isset($data['radius_m']) && !isset($data['lat']) && $cur['shape'] === 'polygon') {
            return ['error' => '"' . $cur['name'] . '" is a drawn polygon, so it has no radius — reshape it on the map card.'];
        }

        try {
            $place = $this->places->update($userId, $cur['id'], $data);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
        $all = $this->places->list($userId);

        return array_filter([
            'updated'  => true,
            'place'    => Places::forModel((array) $place),
            'position' => $position,
            '_render'  => Places::card($all, $cur['id'], PlacePosition::here($this->points, $userId)),
        ], static fn ($v): bool => $v !== null);
    }
}
