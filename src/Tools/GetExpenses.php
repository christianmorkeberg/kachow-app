<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Receipts;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: report recorded business expenses — totals and a per-category breakdown
 * for a period, optionally filtered to one category. Renders a summary card.
 */
final class GetExpenses implements Tool
{
    public function __construct(private Receipts $receipts)
    {
    }

    public function name(): string
    {
        return 'get_expenses';
    }

    public function description(): string
    {
        return 'Reports the user\'s recorded business expenses: the total, VAT, and a per-category '
            . 'breakdown for a period, with the matching receipts. Use for "what have I spent this '
            . 'month", "how much on software this quarter", "show my expenses". The app renders a '
            . 'summary card, so give a brief total rather than listing every receipt. Only confirmed '
            . 'expenses are counted.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period'   => [
                    'type'        => 'string',
                    'enum'        => ['this_month', 'last_month', 'this_quarter', 'this_year', 'all'],
                    'description' => 'Which period. Defaults to this_month. Ignored if from/to given.',
                ],
                'from'     => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (overrides period).'],
                'to'       => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (overrides period).'],
                'category' => ['type' => 'string', 'enum' => Receipts::CATEGORIES, 'description' => 'Limit to one category.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        [$from, $to, $label] = self::resolveRange(
            (string) ($arguments['period'] ?? 'this_month'),
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null
        );
        $category = isset($arguments['category']) ? Receipts::normalizeCategory((string) $arguments['category']) : null;

        $s = $this->receipts->summary($userId, $from, $to, $category);

        $card = [
            'kind'        => 'expenses',
            'title'       => $label . ($category ? ' · ' . $category : ''),
            'currency'    => 'DKK',
            'total'       => $s['total'],
            'vat'         => $s['vat'],
            'count'       => $s['count'],
            'by_category' => $category ? [] : $s['by_category'],
            'items'       => array_map(static fn (array $i): array => [
                'id'       => $i['id'],
                'vendor'   => $i['vendor'],
                'date'     => $i['date'],
                'total'    => $i['total'],
                'vat'      => $i['vat'],
                'currency' => $i['currency'],
                'category' => $i['category'],
            ], $s['items']),
        ];

        return [
            'period'      => $label,
            'total'       => $s['total'],
            'vat'         => $s['vat'],
            'count'       => $s['count'],
            'by_category' => $s['by_category'],
            // Ids included so a follow-up like "delete the Elgiganten one" can act.
            'items'       => array_map(static fn (array $i): array => [
                'id'       => $i['id'],
                'vendor'   => $i['vendor'],
                'date'     => $i['date'],
                'total'    => $i['total'],
                'category' => $i['category'],
            ], $s['items']),
            '_render'     => $card,
        ];
    }

    /**
     * @return array{0:?string, 1:?string, 2:string} [from, to, label]
     */
    public static function resolveRange(string $period, ?string $from, ?string $to): array
    {
        if (($from !== null && $from !== '') || ($to !== null && $to !== '')) {
            $f = $from !== null && $from !== '' ? date('Y-m-d', strtotime($from) ?: time()) : null;
            $t = $to !== null && $to !== '' ? date('Y-m-d', strtotime($to) ?: time()) : null;
            return [$f, $t, trim(($f ?? '…') . ' – ' . ($t ?? '…'))];
        }

        $tz  = new DateTimeZone('Europe/Copenhagen');
        $now = new DateTimeImmutable('now', $tz);

        return match ($period) {
            'all'          => [null, null, 'All time'],
            'last_month'   => self::month($now->modify('first day of last month')),
            'this_quarter' => self::quarter($now),
            'this_year'    => [$now->format('Y') . '-01-01', $now->format('Y') . '-12-31', $now->format('Y')],
            default        => self::month($now), // this_month
        };
    }

    /** @return array{0:string,1:string,2:string} */
    private static function month(DateTimeImmutable $d): array
    {
        return [$d->format('Y-m-01'), $d->format('Y-m-t'), $d->format('F Y')];
    }

    /** @return array{0:string,1:string,2:string} */
    private static function quarter(DateTimeImmutable $now): array
    {
        $q      = intdiv((int) $now->format('n') - 1, 3);      // 0..3
        $start  = $now->setDate((int) $now->format('Y'), $q * 3 + 1, 1);
        $end    = $start->modify('+2 months')->modify('last day of this month');

        return [$start->format('Y-m-d'), $end->format('Y-m-d'), 'Q' . ($q + 1) . ' ' . $now->format('Y')];
    }
}
