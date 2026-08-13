<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Books;

/**
 * Tool: open the bookkeeping cockpit — a single dashboard overview of the books
 * (revenue, moms, expenses, outstanding invoices, reserve, and the income/expense/
 * draw modules) for a period. Renders the modular `bookkeeping` card; once open it is
 * interactive on its own (period switch, drill-in) via api/books.php.
 */
final class GetBooks implements Tool
{
    public function __construct(private Books $books)
    {
    }

    public function name(): string
    {
        return 'get_books';
    }

    public function description(): string
    {
        return 'Opens the BOOKKEEPING cockpit — a dashboard overview of the whole books: revenue, moms '
            . '(salgs − købs), expenses, outstanding invoices, and the tax/moms reserve, plus income, '
            . 'expense and draw modules. Use for "show my books", "open bookkeeping", "how are my finances '
            . 'this quarter", "vis mit regnskab", "åbn bogføringen", "regnskabsoversigt". Prefer this over '
            . 'the single get_income / get_expenses cards when the user wants the OVERALL picture. Defaults '
            . 'to this quarter. The dashboard is interactive once shown, so just open it — no need to also '
            . 'call get_income/get_expenses.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => [
                    'type'        => 'string',
                    'enum'        => ['this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'all'],
                    'description' => 'Which period to open on. Defaults to this_quarter. The user can then page '
                        . 'to other periods within the card.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        // Map the model's period word to a granularity + offset (0 = current, -1 = previous).
        $map = [
            'this_month'   => ['month', 0],   'last_month'   => ['month', -1],
            'this_quarter' => ['quarter', 0], 'last_quarter' => ['quarter', -1],
            'this_year'    => ['year', 0],    'last_year'    => ['year', -1],
            'all'          => ['all', 0],
        ];
        $period = (string) ($arguments['period'] ?? 'this_quarter');
        [$gran, $offset] = $map[$period] ?? ['quarter', 0];

        $card = $this->books->overview($userId, $gran, $offset);

        return [
            'opened'   => true,
            'period'   => $card['period_label'],
            'revenue'  => $card['kpis']['revenue'],
            'net_moms' => $card['kpis']['net_moms'],
            'reserve'  => $card['kpis']['reserve']['total'],
            '_render'  => $card,
        ];
    }
}
