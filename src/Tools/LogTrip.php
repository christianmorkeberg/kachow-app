<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Mileage;

/**
 * Tool: log a business driving day (kørsel) to the customer. Distance defaults to the
 * saved round-trip distance. The 60-day rule then classifies it (business vs commuter)
 * and it flows into the mileage deduction. Renders the mileage card.
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
        return 'Logs a driving day to your customer for the mileage deduction (kørsel). Use for "I drove to '
            . 'the customer today", "log my driving for today", "jeg kørte på arbejde i dag", "registrér min '
            . 'kørsel". Distance defaults to the saved round-trip distance (set it with update_setting '
            . 'mileage_round_trip_km if not set). Optionally give a date, km (to override), or note. The 60-day '
            . 'rule decides whether it counts as business driving or commuting.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
                'km'   => ['type' => 'number', 'description' => 'Round-trip km, if different from the saved default.'],
                'note' => ['type' => 'string', 'description' => 'Optional note.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $km = isset($arguments['km']) && $arguments['km'] !== '' ? (float) $arguments['km'] : null;
        $this->mileage->logTrip(
            $userId,
            isset($arguments['date']) ? (string) $arguments['date'] : null,
            $km,
            isset($arguments['note']) ? (string) $arguments['note'] : null
        );
        $card = $this->mileage->card($userId, 0);

        return [
            'logged'            => true,
            'business_deduction' => $card['business']['amount'],
            'business_days_used' => $card['counter']['business_used'],
            'days_remaining'    => $card['counter']['remaining'],
            'commuting_now'     => $card['counter']['commuting_now'],
            '_render'           => $card,
        ];
    }
}
