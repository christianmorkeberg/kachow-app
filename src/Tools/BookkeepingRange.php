<?php

declare(strict_types=1);

namespace App\Tools;

/** Resolves the period arguments the bookkeeping report tools share: year, from/to, or a named period. */
final class BookkeepingRange
{
    public const PARAMS = [
        'year'   => ['type' => 'integer', 'description' => 'A whole calendar year, e.g. 2025 (regnskabsår). Overrides period.'],
        'period' => ['type' => 'string', 'enum' => ['this_month', 'last_month', 'this_quarter', 'this_year', 'last_year', 'all'], 'description' => 'Defaults to this_year. Ignored if year or from/to is given.'],
        'from'   => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (overrides period).'],
        'to'     => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (overrides period).'],
    ];

    /** @return array{0:?string, 1:?string, 2:string} from, to, Danish label */
    public static function resolve(array $a): array
    {
        $year = (int) ($a['year'] ?? 0);
        if ($year >= 2000 && $year <= 2100) {
            return [$year . '-01-01', $year . '-12-31', (string) $year];
        }
        $period = (string) ($a['period'] ?? 'this_year');
        if ($period === 'last_year' && empty($a['from']) && empty($a['to'])) {
            $y = (int) date('Y') - 1;

            return [$y . '-01-01', $y . '-12-31', (string) $y];
        }
        [$f, $t, $label] = GetExpenses::resolveRange($period === 'last_year' ? 'this_year' : $period, isset($a['from']) ? (string) $a['from'] : null, isset($a['to']) ? (string) $a['to'] : null);

        return [$f, $t, $label === 'All time' ? 'Hele perioden' : $label];
    }
}
