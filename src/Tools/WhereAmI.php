<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Data\Places;
use App\Maps\MapDistance;
use App\Support\Geo;
use App\Support\PlaceNow;

/**
 * Tool: where the user is now, from their tracked position — which saved places / regions they are in, and, against a reference, how far
 * and in which direction (east / west / north / south). The assistant CAN work with the user's coordinates: this is how.
 */
final class WhereAmI implements Tool
{
    public function __construct(private Places $places, private LocationPoints $points, private MapDistance $maps)
    {
    }

    public function name(): string
    {
        return 'where_am_i';
    }

    public function description(): string
    {
        return 'Says where the user is RIGHT NOW from their tracked position: how old the position is, the saved places and regions they are inside, and — when you pass a '
            . 'reference (reference_place = a saved place, reference_address = a town, or lat + lon the user gave) — the distance, the compass direction and plain true / false '
            . 'for east_of / west_of / north_of / south_of it. Use for "am I east of Roskilde?", "how far from home am I?", "which side of the bridge am I on?", "er jeg øst for '
            . 'Roskilde?". If the position is old, say how old. Do not say you cannot read their position: use this.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'reference_place'   => ['type' => 'string', 'description' => 'A saved place to compare with.'],
                'reference_address' => ['type' => 'string', 'description' => 'Or a town / address ("Roskilde").'],
                'lat'               => ['type' => 'number', 'description' => 'Or coordinates the user gave (with lon).'],
                'lon'               => ['type' => 'number'],
            ],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $last = $this->points->latest($userId);
        if ($last === null) {
            return ['known' => false, 'note' => 'No tracked position yet — location tracking is not set up (get_location_tracking_setup).'];
        }
        $age   = max(0, (int) round((time() - (int) strtotime($last['recorded_at'] . ' UTC')) / 60));
        $fresh = PlaceNow::usable($last, time()) !== null;
        $in    = [];
        foreach ($this->places->list($userId) as $pl) {
            if (Places::contains($pl, $last['lat'], $last['lon'])) {
                $in[] = (string) $pl['name'];
            }
        }
        $out = [
            'known'       => true,
            'position'    => ['lat' => round($last['lat'], 4), 'lon' => round($last['lon'], 4), 'accuracy_m' => $last['acc']],
            'age_minutes' => $age,
            'fresh'       => $fresh,
            'inside'      => $in,
            'note'        => $fresh ? 'The position is fresh.' : 'The position is ' . $age . ' min old (or imprecise): say so, they may have moved.',
        ];
        $wantsRef = trim((string) ($arguments['reference_place'] ?? '')) !== '' || trim((string) ($arguments['reference_address'] ?? '')) !== '' || isset($arguments['lat'], $arguments['lon']);
        if ($wantsRef) {
            $ref = SaveRegion::reference($arguments, $this->places, $this->points, $this->maps, $userId);
            if (isset($ref['error'])) {
                return $out + ['error' => $ref['error']];
            }
            $out['relative_to'] = ['reference' => $ref['from']] + Geo::relative($last['lat'], $last['lon'], $ref['lat'], $ref['lon']);
        }

        return $out;
    }
}
