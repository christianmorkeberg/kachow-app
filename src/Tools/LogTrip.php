<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Mileage;

/**
 * Tool: log a driving day (kørsel) to a destination. Distance defaults to that
 * destination's saved round-trip distance. The 60-day rule then classifies the day
 * (business vs commuter) and it flows into the mileage deduction. Renders the mileage card.
 */
final class LogTrip implements Tool
{
    public function __construct(private Mileage $mileage)
    {
    }

    public function name(): string
    {
        return 'log_trip';
    }

    public function description(): string
    {
        return 'Logs a driving day for the mileage deduction (kørsel). Use for "I drove to the customer today", '
            . '"log my driving", "jeg kørte på arbejde i dag", "registrér min kørsel". Pass "destination" with '
            . 'the place name if the user has more than one (e.g. a customer vs DTU); omit it to use their default. '
            . 'Distance defaults to that destination\'s saved round-trip distance. Optionally give a date, km (to '
            . 'override), or note. Business destinations follow the 60-day rule (business → commuting); commute '
            . 'destinations (a fixed workplace like DTU) always count as befordringsfradrag.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'destination' => ['type' => 'string', 'description' => 'Destination name, if the user has more than one (e.g. "DTU" or a customer). Omit for the default.'],
                'date'        => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
                'km'          => ['type' => 'number', 'description' => 'Round-trip km, if different from the destination default.'],
                'note'        => ['type' => 'string', 'description' => 'Optional note.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $destId  = null;
        $destName = isset($arguments['destination']) ? trim((string) $arguments['destination']) : '';
        $unmatched = false;
        if ($destName !== '') {
            $destId = $this->mileage->findDestinationByName($userId, $destName);
            $unmatched = $destId === null;
        }

        $km = isset($arguments['km']) && $arguments['km'] !== '' ? (float) $arguments['km'] : null;
        $this->mileage->logTrip(
            $userId,
            $destId,
            isset($arguments['date']) ? (string) $arguments['date'] : null,
            $km,
            isset($arguments['note']) ? (string) $arguments['note'] : null
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
        if ($unmatched) {
            $result['note'] = 'No destination named "' . $destName . '" — logged to the default. '
                . 'The user can add it from the mileage card.';
        }

        return $result;
    }
}
