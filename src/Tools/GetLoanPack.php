<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LoanPack;

/** Tool: the lånepakke / regnskabspakke for a year — opens the card; the export asks for the full rows. */
final class GetLoanPack implements Tool
{
    public function __construct(private LoanPack $pack)
    {
    }

    public function name(): string
    {
        return 'get_loan_pack';
    }

    public function description(): string
    {
        return 'Opens the REGNSKABSPAKKE / lånepakke card for a calendar year — one place with resultatopgørelse, balance, moms pr. kvartal, fakturaer (pr. kunde and pr. faktura), '
            . 'saldobalance, kassekladde and a 12-month budget, with key figures, PDF / CSV / bilag-ZIP buttons and a form to enter balance items (equipment, loans, bank balance). '
            . 'Use for "lav en lånepakke", "regnskab til banken", "åbn regnskabspakken", "samle mit regnskab for 2025", "loan pack". The card is interactive (year switch, tabs, downloads), '
            . 'so just open it and say in one line what it shows — do not read the tables out and do not paste links. Year defaults to last full year before March, else this year. '
            . 'For a single file by email use export_data with source get_loan_pack.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'year'           => ['type' => 'integer', 'description' => 'Calendar year of the statements.'],
            'include_budget' => ['type' => 'boolean', 'description' => 'Export only: add the 12-month budget section.'],
            'growth_pct'     => ['type' => 'number', 'description' => 'Export only, budget: percent to move the averages.'],
        ], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $year = (int) ($arguments['year'] ?? 0);
        $year = $year >= 2000 && $year <= 2100 ? $year : LoanPack::defaultYear();

        if (!empty($arguments['_export'])) {   // the export: every row of every section
            $p = $this->pack->build($userId, $year, !empty($arguments['include_budget']), (float) ($arguments['growth_pct'] ?? 0));

            return ['periode' => (string) $year, 'aar' => $year, 'sections' => $p['sections']];
        }
        $card = $this->pack->card($userId, $year);

        return [
            'aar' => $year, 'noegletal' => $card['key'], 'bank' => $card['bank_source'], 'haandterede_poster' => count($card['items']),
            'afsnit' => array_map(static fn (array $s): string => $s['title'] . ' (' . count($s['rows']) . ')', $card['sections']),
            '_render' => $card,
        ];
    }
}
