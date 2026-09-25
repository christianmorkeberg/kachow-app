<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Mileage;

/**
 * Tool: delete a logged driving day (a duplicate or a trip that didn't happen).
 */
final class DeleteTrip implements Tool
{
    public function __construct(private Mileage $mileage)
    {
    }

    public function name(): string
    {
        return 'delete_trip';
    }

    public function description(): string
    {
        return 'Deletes one logged driving day (kørsel) by its id — e.g. a duplicate ("there are two for '
            . 'today", "der ligger to for i dag") or a trip that didn\'t happen ("slet turen"). Get the id '
            . 'from get_mileage (its `trips` list). Only delete a trip the user clearly identified; when two '
            . 'trips on the same day differ (destination/km), delete the one the user says is wrong. To '
            . 'CHANGE a trip (destination, date, km), use update_trip instead.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'trip_id' => ['type' => 'integer', 'description' => 'The trip id (from get_mileage `trips`).'],
            ],
            'required' => ['trip_id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['trip_id'] ?? 0);
        if ($id <= 0) {
            return ['deleted' => false, 'error' => 'A valid trip_id is required (from get_mileage).'];
        }
        $trip = $this->mileage->findTrip($userId, $id);
        if ($trip === null || !$this->mileage->deleteTrip($userId, $id)) {
            return ['deleted' => false, 'error' => 'No such trip (it may already be gone).'];
        }

        $card = $this->mileage->card($userId, 0);

        return [
            'deleted'            => true,
            'deleted_trip'       => ['id' => $id, 'date' => $trip['date'], 'km' => $trip['km']],
            'remaining_that_day' => Mileage::tripsForModel($card, 50, $trip['date']),
            'business_deduction' => $card['business']['amount'],
            'commuter_estimate'  => $card['commuter']['amount'],
            '_render'            => $card,
        ];
    }
}
