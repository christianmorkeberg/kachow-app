<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LoanReports;

/** Tool: moms pr. kvartal for a year. */
final class GetMomsByQuarter implements Tool
{
    public function __construct(private LoanReports $reports)
    {
    }

    public function name(): string
    {
        return 'get_moms_by_quarter';
    }

    public function description(): string
    {
        return 'MOMS PR. KVARTAL for a year: salgsmoms, købsmoms, tilsvar (to pay) and what was paid to SKAT per quarter, plus a year total. Use for "moms for hele året", '
            . '"momsoversigt 2025", "moms pr. kvartal". For ONE quarter\'s card use get_moms. For a file call export_data with source get_moms_by_quarter (language da).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['year' => ['type' => 'integer', 'description' => 'Calendar year, default this year.']], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $year = (int) ($arguments['year'] ?? 0);
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');

        return ['aar' => $year, 'kvartaler' => $this->reports->momsQuarters($userId, $year)];
    }
}
