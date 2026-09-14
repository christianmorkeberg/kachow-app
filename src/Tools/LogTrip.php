<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Mileage;
use App\Data\UserSettings;
use App\Maps\MapDistance;
use RuntimeException;

/**
 * Tool: log a driving day (kørsel) to a destination. Distance defaults to that
 * destination's saved round-trip distance, OR is computed from a from/to address pair
 * ("log home to Middelfart station"). The 60-day rule then classifies the day (business
 * vs commuter) and it flows into the mileage deduction. Renders the mileage card.
 */
final class LogTrip implements Tool
{
    public function __construct(
        private Mileage $mileage,
        private MapDistance $maps,
        private UserSettings $settings
    ) {
    }

    public function name(): string
    {
        return 'log_trip';
    }

    public function description(): string
    {
        return 'Logs a driving day for the mileage deduction (kørsel). Use for "I drove to the customer today", '
            . '"log my driving", "jeg kørte på arbejde i dag", "log home to Middelfart station". Distance comes '
            . 'from (in order): an explicit km; else a from/to address pair computed via the map (round trip); '
            . 'else the destination\'s saved round-trip distance. Pass "destination" with the place name if the '
            . 'user has more than one (e.g. a customer vs DTU); omit to use their default. "from" defaults to the '
            . 'user\'s home address. Optionally give a date or note. Business destinations follow the 60-day rule; '
            . 'commute destinations (a fixed workplace like DTU) always count as befordringsfradrag.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'destination' => ['type' => 'string', 'description' => 'Destination name, if the user has more than one (e.g. "DTU" or a customer). Omit for the default.'],
                'from'        => ['type' => 'string', 'description' => 'Start address for a map-computed distance. Use "home" (or omit) for the saved home address. Only used when km is not given.'],
                'to'          => ['type' => 'string', 'description' => 'Destination address for a map-computed distance (e.g. "Middelfart station"). Only used when km is not given.'],
                'date'        => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
                'km'          => ['type' => 'number', 'description' => 'Round-trip km, if you already know it (overrides map lookup and the destination default).'],
                'note'        => ['type' => 'string', 'description' => 'Optional note.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $destId    = null;
        $destName  = isset($arguments['destination']) ? trim((string) $arguments['destination']) : '';
        $unmatched = false;
        if ($destName !== '') {
            $destId    = $this->mileage->findDestinationByName($userId, $destName);
            $unmatched = $destId === null;
        }

        $km   = isset($arguments['km']) && $arguments['km'] !== '' ? (float) $arguments['km'] : null;
        $note = isset($arguments['note']) ? (string) $arguments['note'] : null;

        // If no km given but a route was described, compute the round-trip distance.
        $lookupNote = null;
        $to = isset($arguments['to']) ? trim((string) $arguments['to']) : '';
        if ($km === null && $to !== '') {
            $from = $this->resolveFrom($userId, isset($arguments['from']) ? (string) $arguments['from'] : '');
            if ($from === null) {
                return ['logged' => false, 'error' => 'No home address saved — set it (settings → Home address) or give an explicit "from" or km.'];
            }
            if (!$this->maps->isConfigured()) {
                return ['logged' => false, 'error' => 'Map lookup is not set up — give the km directly.'];
            }
            try {
                $res = $this->maps->lookup($from, $to);
            } catch (RuntimeException $e) {
                return ['logged' => false, 'error' => $e->getMessage()];
            }
            $km         = $res['round_trip_km'];
            $lookupNote = $res['from'] . ' → ' . $res['to'];
            if ($note === null || trim($note) === '') {
                $note = $lookupNote . ' (' . $res['one_way_km'] . ' km ' . 'each way)';
            }
        }

        $this->mileage->logTrip(
            $userId,
            $destId,
            isset($arguments['date']) ? (string) $arguments['date'] : null,
            $km,
            $note
        );
        $card = $this->mileage->card($userId, 0);

        $result = [
            'logged'             => true,
            'business_deduction' => $card['business']['amount'],
            'destinations'       => array_map(static fn (array $d): array => [
                'name'      => $d['name'],
                'type'      => $d['type'],
                'remaining' => $d['counter']['remaining'] ?? null,
            ], $card['destinations']),
            '_render'            => $card,
        ];
        if ($lookupNote !== null) {
            $result['computed_route'] = $lookupNote;
            $result['round_trip_km']  = $km;
        }
        if ($unmatched) {
            $result['note'] = 'No destination named "' . $destName . '" — logged to the default. '
                . 'The user can add it from the mileage card.';
        }

        return $result;
    }

    /** Resolves "home"/"hjem"/empty to the saved home address; null if needed but unset. */
    private function resolveFrom(int $userId, string $from): ?string
    {
        $from = trim($from);
        $isHome = $from === '' || in_array(mb_strtolower($from), ['home', 'hjem', 'hjemme'], true);
        if ($isHome) {
            $home = trim((string) $this->settings->get($userId, 'home_address'));
            return $home !== '' ? $home : null;
        }

        return $from;
    }
}
