<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Data\Places;
use App\Maps\MapDistance;
use App\Support\Geo;
use InvalidArgumentException;

/**
 * Tool: save a REGION as a place — everything east / west / north / south of a reference ("east of Roskilde", "south of the bridge") — as
 * a polygon. Being in it, entering it and leaving it then work like any saved place: routines, conditions and reminders can use it.
 */
final class SaveRegion implements Tool
{
    public function __construct(private Places $places, private LocationPoints $points, private MapDistance $maps)
    {
    }

    public function name(): string
    {
        return 'save_region';
    }

    public function description(): string
    {
        return 'Saves a REGION as a place: the area east_of / west_of / north_of / south_of a reference, out to extent_km (default 150) — for "tell me when I am east of '
            . 'Roskilde", "when I have crossed the Great Belt". The reference is one of their saved places (reference_place), an address or town (reference_address), or '
            . 'coordinates THE USER gave (lat + lon). It is an ordinary place afterwards: create_routine can trigger on entering it (trigger_place) or require it as a condition '
            . '(conditions.in_places), and where_am_i says whether they are in it. Name it after what it means ("East of Roskilde"). Never invent coordinates.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name'              => ['type' => 'string', 'description' => 'E.g. "East of Roskilde".'],
                'side'              => ['type' => 'string', 'enum' => Geo::SIDES, 'description' => 'Which side of the reference.'],
                'reference_place'   => ['type' => 'string', 'description' => 'The name of a saved place to measure from (list_places).'],
                'reference_address' => ['type' => 'string', 'description' => 'Or a town / address to measure from ("Roskilde").'],
                'lat'               => ['type' => 'number', 'description' => 'Or coordinates the user gave (with lon).'],
                'lon'               => ['type' => 'number'],
                'extent_km'         => ['type' => 'integer', 'description' => 'How far the region reaches, ' . Geo::MIN_EXTENT_KM . '–' . Geo::MAX_EXTENT_KM . ' km (default ' . Geo::DEFAULT_EXTENT_KM . ').'],
                'type'              => ['type' => 'string', 'enum' => Places::TYPES, 'description' => 'Default "commute".'],
            ],
            'required' => ['name', 'side'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $side = (string) ($arguments['side'] ?? '');
        if (!in_array($side, Geo::SIDES, true)) {
            return ['error' => 'side must be one of ' . implode(', ', Geo::SIDES) . '.'];
        }
        $ref = self::reference($arguments, $this->places, $this->points, $this->maps, $userId);
        if (isset($ref['error'])) {
            return $ref;
        }
        $poly = Geo::region($side, $ref['lat'], $ref['lon'], (float) ($arguments['extent_km'] ?? Geo::DEFAULT_EXTENT_KM));
        if ($poly === null) {
            return ['error' => 'That reference cannot make a region.'];
        }
        try {
            $place = $this->places->add($userId, ['name' => $arguments['name'] ?? '', 'type' => $arguments['type'] ?? 'commute', 'polygon' => $poly]);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
        $all = $this->places->list($userId);

        return [
            'saved'     => true,
            'place'     => Places::forModel($place),
            'region'    => str_replace('_', ' ', $side) . ' ' . $ref['from'] . ', out to ' . (int) round(max(Geo::MIN_EXTENT_KM, min(Geo::MAX_EXTENT_KM, (float) ($arguments['extent_km'] ?? Geo::DEFAULT_EXTENT_KM)))) . ' km',
            'places'    => array_map([Places::class, 'forModel'], $all),
            '_render'   => Places::card($all, $place['id'], PlacePosition::here($this->points, $userId)),
        ];
    }

    /**
     * A reference point from the arguments: a saved place, an address, or coordinates the user gave.
     *
     * @return array{lat:float, lon:float, from:string}|array{error:string}
     */
    public static function reference(array $a, Places $places, LocationPoints $points, MapDistance $maps, int $userId): array
    {
        $name = trim((string) ($a['reference_place'] ?? ''));
        if ($name !== '') {
            $p = $places->findByName($userId, $name);
            if ($p === null) {
                return ['error' => 'No saved place called "' . $name . '" (list_places shows them) — or give reference_address.'];
            }

            return ['lat' => (float) $p['lat'], 'lon' => (float) $p['lon'], 'from' => (string) $p['name']];
        }
        if (isset($a['lat'], $a['lon']) && is_numeric($a['lat']) && is_numeric($a['lon']) && abs((float) $a['lat']) <= 90 && abs((float) $a['lon']) <= 180) {
            return ['lat' => (float) $a['lat'], 'lon' => (float) $a['lon'], 'from' => sprintf('%.4f, %.4f', (float) $a['lat'], (float) $a['lon'])];
        }
        $address = trim((string) ($a['reference_address'] ?? ''));
        if ($address === '') {
            return ['error' => 'Say what to measure from: reference_place (a saved place), reference_address (a town or address) or lat + lon the user gave.'];
        }
        $r = PlacePosition::resolve($points, $maps, $userId, $address);
        if (isset($r['error'])) {
            return $r;
        }

        return ['lat' => $r['lat'], 'lon' => $r['lon'], 'from' => preg_replace('/^address:\s*/', '', $r['from']) ?? $address];
    }
}
