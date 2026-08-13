<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Income;
use App\Tools\GetExpenses;

/**
 * Tool: report recorded business income for a period — totals (net, moms, gross) per
 * currency, what's still outstanding (unpaid invoices), and a per-customer breakdown.
 * Renders a summary card.
 */
final class GetIncome implements Tool
{
    public function __construct(private Income $income)
    {
    }

    public function name(): string
    {
        return 'get_income';
    }

    public function description(): string
    {
        return 'Reports the user\'s recorded business INCOME (invoices + revenue): net (ex-moms), moms '
            . '(output VAT), and gross totals for a period, plus outstanding unpaid invoices and a '
            . 'per-customer breakdown. Use for "how much have I invoiced this quarter", "what\'s my revenue '
            . 'this month", "hvor meget har jeg faktureret", "what\'s outstanding / hvem skylder mig". The app '
            . 'renders a summary card, so give a brief total rather than listing every invoice. Only booked '
            . 'income is counted. This is INCOME, not expenses (that is get_expenses).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => [
                    'type'        => 'string',
                    'enum'        => ['this_month', 'last_month', 'this_quarter', 'this_year', 'all'],
                    'description' => 'Which period. Defaults to this_quarter. Ignored if from/to given.',
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
            (string) ($arguments['period'] ?? 'this_quarter'),
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null
        );

        $s    = $this->income->summary($userId, $from, $to);
        $card = $this->income->summaryCard($userId, $from, $to, $label);

        return [
            'period'      => $label,
            'currencies'  => $s['currencies'],
            'outstanding' => $s['outstanding'],
            'by_customer' => $s['by_customer'],
            'count'       => $s['count'],
            'items'       => array_map(static fn (array $i): array => [
                'id'         => $i['id'],
                'doc_number' => $i['doc_number'],
                'customer'   => $i['customer'],
                'date'       => $i['date'],
                'total'      => $i['total'],
                'currency'   => $i['currency'],
                'paid'       => $i['paid'],
            ], $s['items']),
            '_render' => $card,
        ];
    }
}
