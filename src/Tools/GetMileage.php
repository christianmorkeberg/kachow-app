<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Mileage;

/**
 * Tool: show the mileage (kørsel) card — this year's business driving deduction, the
 * separate commuter befordringsfradrag estimate, and the 60-day counter.
 */
final class GetMileage implements Tool
{
    public function __construct(private Mileage $mileage)
    {
    }

    public function name(): string
    {
        return 'get_mileage';
    }

    public function description(): string
    {
        return 'Shows driving / mileage (kørsel): this year\'s business driving deduction (statens takst, the '
            . 'first 60 days at your customer) and, separately, the commuter befordringsfradrag estimate for '
            . 'day 61+, with a "X of 60 business days used" counter. Use for "show my mileage", "how much '
            . 'driving deduction do I have", "kørselsfradrag", "min kørsel", "how many driving days left". '
            . 'Business driving lowers your profit + tax reserve; the commuter part is a personal-return figure.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $card = $this->mileage->card($userId, 0);

        return [
            'business_deduction' => $card['business']['amount'],
            'business_days'      => $card['business']['days'],
            'commuter_estimate'  => $card['commuter']['amount'],
            'days_remaining'     => $card['counter']['remaining'],
            'commuting_now'      => $card['counter']['commuting_now'],
            '_render'            => $card,
        ];
    }
}
