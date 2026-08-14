<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ProfitLoss;

/**
 * Tool: the profit & loss statement (resultatopgørelse) for a period — revenue minus
 * expenses (by category) = profit, all ex-VAT, plus the tax-reserve estimate. Renders
 * the interactive `pl` card (page between periods via api/pl.php).
 */
final class GetProfitLoss implements Tool
{
    public function __construct(private ProfitLoss $pl)
    {
    }

    public function name(): string
    {
        return 'get_profit_loss';
    }

    public function description(): string
    {
        return 'Shows the profit & loss statement (resultatopgørelse) for a period: revenue − expenses '
            . '(broken down by category) = profit, all EX-VAT, plus the income-tax set-aside estimate. Use '
            . 'for "show my profit and loss", "resultatopgørelse", "how much did I earn/profit", "am I in '
            . 'profit this quarter", "expenses by category", "hvad er mit overskud", "vis resultatopgørelse". '
            . 'Defaults to this year; can page to other periods within the card. For the whole dashboard use '
            . 'get_books; for cash-in-the-bank use get_cash.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => [
                    'type'        => 'string',
                    'enum'        => ['this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'all'],
                    'description' => 'Which period. Defaults to this_year. The user can page to others in the card.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $map = [
            'this_month'   => ['month', 0],   'last_month'   => ['month', -1],
            'this_quarter' => ['quarter', 0], 'last_quarter' => ['quarter', -1],
            'this_year'    => ['year', 0],    'last_year'    => ['year', -1],
            'all'          => ['all', 0],
        ];
        $period = (string) ($arguments['period'] ?? 'this_year');
        [$gran, $offset] = $map[$period] ?? ['year', 0];

        $card = $this->pl->statement($userId, $gran, $offset);

        return [
            'period'   => $card['period_label'],
            'revenue'  => $card['revenue'],
            'expenses' => $card['expenses'],
            'profit'   => $card['profit'],
            '_render'  => $card,
        ];
    }
}
