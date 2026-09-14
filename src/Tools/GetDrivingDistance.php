<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserSettings;
use App\Maps\MapDistance;
use RuntimeException;

/**
 * Tool: driving distance between two addresses (one-way + round-trip km), via
 * OpenRouteService. "home" / "hjem" (or an empty from) resolves to the user's saved
 * home_address. Pure lookup — no side effects; the model uses the number to answer
 * ("how far is X") or to feed log_trip.
 */
final class GetDrivingDistance implements Tool
{
    public function __construct(private MapDistance $maps, private UserSettings $settings)
    {
    }

    public function name(): string
    {
        return 'get_driving_distance';
    }

    public function description(): string
    {
        return 'Looks up the driving distance between two addresses and returns one-way and round-trip km. '
            . 'Use for "how far is it from home to X", "hvor langt er der til …", or to get the km before '
            . 'logging a driving day. "home"/"hjem" (or an empty from) uses the user\'s saved home address. '
            . 'Give real addresses or place names (e.g. "Middelfart station", "Frederiksborgvej 399, Roskilde").';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Start address or place. Use "home" (or omit) for the saved home address.'],
                'to'   => ['type' => 'string', 'description' => 'Destination address or place.'],
            ],
            'required' => ['to'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->maps->isConfigured()) {
            return ['ok' => false, 'error' => 'Map lookup is not set up (no routing API key configured).'];
        }

        $from = $this->resolveFrom($userId, isset($arguments['from']) ? (string) $arguments['from'] : '');
        $to   = isset($arguments['to']) ? trim((string) $arguments['to']) : '';
        if ($from === null) {
            return ['ok' => false, 'error' => 'No home address saved — set it first (settings → Home address), or give an explicit "from".'];
        }
        if ($to === '') {
            return ['ok' => false, 'error' => 'A destination ("to") is required.'];
        }

        try {
            $res = $this->maps->lookup($from, $to);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok'            => true,
            'one_way_km'    => $res['one_way_km'],
            'round_trip_km' => $res['round_trip_km'],
            'from'          => $res['from'],
            'to'            => $res['to'],
        ];
    }

    /** Resolves "home"/"hjem"/empty to the saved home address; returns null if that's needed but unset. */
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
