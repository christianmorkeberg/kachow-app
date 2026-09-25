<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Mileage;
use RuntimeException;

/**
 * Tool: correct a logged driving day (destination, date, km or note) — e.g. "that trip was
 * to the office, not the customer". Fixes the row in place instead of logging a duplicate.
 */
final class UpdateTrip implements Tool
{
    public function __construct(private Mileage $mileage)
    {
    }

    public function name(): string
    {
        return 'update_trip';
    }

    public function description(): string
    {
        return 'Corrects an ALREADY-LOGGED driving day (kørsel) in place: move it to another destination '
            . '(e.g. business customer → a commute workplace, which changes business vs commute), '
            . 'or change its date, km or note. Use when the user says a logged trip is wrong ("that was '
            . 'commute, not business", "ret turen", "forkert dato"). Get the trip id from '
            . 'get_mileage (its `trips` list). NEVER fix a wrong trip by calling log_trip again — that adds a '
            . 'duplicate; update the existing trip instead. Moving a trip to another destination without a km '
            . 'uses that destination\'s saved round-trip distance.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'trip_id'     => ['type' => 'integer', 'description' => 'The trip id (from get_mileage `trips`).'],
                'destination' => ['type' => 'string', 'description' => 'New destination name (e.g. "Office"). Omit to keep.'],
                'date'        => ['type' => 'string', 'description' => 'New date YYYY-MM-DD. Omit to keep.'],
                'km'          => ['type' => 'number', 'description' => 'New round-trip km. Omit to keep (or to use the new destination\'s distance).'],
                'note'        => ['type' => 'string', 'description' => 'New note. Omit to keep.'],
            ],
            'required' => ['trip_id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['trip_id'] ?? 0);
        if ($id <= 0) {
            return ['updated' => false, 'error' => 'A valid trip_id is required (from get_mileage).'];
        }

        $fields = [];
        $dest   = trim((string) ($arguments['destination'] ?? ''));
        if ($dest !== '') {
            $destId = $this->mileage->findDestinationByName($userId, $dest);
            if ($destId === null) {
                return ['updated' => false, 'error' => 'No destination named "' . $dest . '". Check the names in get_mileage.'];
            }
            $fields['destination_id'] = $destId;
        }
        if (isset($arguments['date']) && trim((string) $arguments['date']) !== '') {
            $fields['date'] = (string) $arguments['date'];
        }
        if (isset($arguments['km']) && $arguments['km'] !== '' && (float) $arguments['km'] > 0) {
            $fields['km'] = (float) $arguments['km'];
        }
        if (array_key_exists('note', $arguments)) {
            $fields['note'] = (string) $arguments['note'];
        }
        if ($fields === []) {
            return ['updated' => false, 'error' => 'Nothing to change — give a destination, date, km or note.'];
        }

        try {
            $ok = $this->mileage->updateTrip($userId, $id, $fields);
        } catch (RuntimeException $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }
        if (!$ok) {
            return ['updated' => false, 'error' => 'No such trip (it may have been deleted).'];
        }

        $card = $this->mileage->card($userId, 0);
        $trip = $this->mileage->findTrip($userId, $id);

        return [
            'updated'            => true,
            'trip'               => $trip !== null ? Mileage::tripsForModel($card, 50, $trip['date']) : [],
            'business_deduction' => $card['business']['amount'],
            'commuter_estimate'  => $card['commuter']['amount'],
            '_render'            => $card,
        ];
    }
}
