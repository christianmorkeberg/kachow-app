<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\OwnerDraws;
use App\Tools\GetExpenses;

/**
 * Tool: report owner drawings (private hævninger) for a period — how much the user has
 * paid themselves out of the business.
 */
final class GetOwnerDraws implements Tool
{
    public function __construct(private OwnerDraws $draws)
    {
    }

    public function name(): string
    {
        return 'get_owner_draws';
    }

    public function description(): string
    {
        return 'Reports owner DRAWINGS (private hævninger) for a period — how much the user has paid '
            . 'themselves out of the business ("how much have I taken out this year", "hvor meget har jeg '
            . 'hævet"). These are private withdrawals, not expenses. Renders a summary card, so give a brief '
            . 'total rather than listing every draw.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => [
                    'type'        => 'string',
                    'enum'        => ['this_month', 'last_month', 'this_quarter', 'this_year', 'all'],
                    'description' => 'Which period. Defaults to this_year. Ignored if from/to given.',
                ],
                'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (overrides period).'],
                'to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (overrides period).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        [$from, $to, $label] = GetExpenses::resolveRange(
            (string) ($arguments['period'] ?? 'this_year'),
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null
        );

        $s = $this->draws->summary($userId, $from, $to);

        return [
            'period'  => $label,
            'totals'  => $s['totals'],
            'count'   => $s['count'],
            'items'   => $s['items'],
            '_render' => $this->draws->card($userId, $from, $to, 'Owner draws · ' . $label),
        ];
    }
}
