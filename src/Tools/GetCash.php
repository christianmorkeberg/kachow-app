<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Cash;

/**
 * Tool: open the cash position — the EXPECTED bank balance ("how much should be in my
 * account") plus how much of it is free to spend after setting aside moms + tax. A cash
 * view (money that actually moved), distinct from the accrual moms/P&L views.
 */
final class GetCash implements Tool
{
    public function __construct(private Cash $cash)
    {
    }

    public function name(): string
    {
        return 'get_cash';
    }

    public function description(): string
    {
        return 'Shows the CASH position — how much SHOULD be in the bank account right now (opening balance '
            . '+ invoices paid − expenses paid − owner draws − moms/other payments) and how much of that is '
            . 'free to spend after setting aside moms and income tax. Use for "how much should be in my '
            . 'account", "what\'s my bank balance", "how much money do I actually have", "hvor meget står der '
            . 'på kontoen", "hvor meget kan jeg bruge", "likviditet", "kontosaldo". This is a cash view, '
            . 'different from get_moms (what you owe SKAT) and the accrual figures in get_books.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $card = $this->cash->position($userId);

        return [
            'opened'        => true,
            'expected'      => $card['expected'],
            'free_to_spend' => $card['free_to_spend'],
            'reserve'       => $card['reserve']['total'],
            '_render'       => $card,
        ];
    }
}
