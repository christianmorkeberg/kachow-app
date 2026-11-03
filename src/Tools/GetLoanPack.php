<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\BalanceItems;
use App\Data\BalanceSheet;
use App\Data\Ledger;
use App\Data\LoanReports;
use App\Data\Mileage;
use App\Data\UserSettings;

/** Tool: the whole lånepakke / regnskabspakke for a year as one document (several sections). */
final class GetLoanPack implements Tool
{
    public function __construct(
        private Ledger $ledger,
        private LoanReports $reports,
        private BalanceSheet $sheet,
        private BalanceItems $items,
        private UserSettings $settings,
        private Mileage $mileage,
    ) {
    }

    public function name(): string
    {
        return 'get_loan_pack';
    }

    public function description(): string
    {
        return 'The LÅNEPAKKE / regnskabspakke for a year as ONE document: resultatopgørelse, balance pr. årets udgang (or today for the current year), moms pr. kvartal, '
            . 'fakturaoversigt (per customer and per invoice), saldobalance and kassekladde — optionally a 12-month budget. Use for "lav en lånepakke", "regnskab til banken", '
            . '"samle mit regnskab for 2025", "loan pack". To give the user the file call export_data with source get_loan_pack (language da) — a bundle of the bilag as a ZIP '
            . 'is offered next to the PDF. Ask for nothing you can default: year defaults to last full year if it is before March, else this year.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'year'           => ['type' => 'integer', 'description' => 'Calendar year of the statements.'],
            'include_budget' => ['type' => 'boolean', 'description' => 'Add a 12-month budget section (projected from the last 12 months). Default false.'],
            'growth_pct'     => ['type' => 'number', 'description' => 'Budget only: percent to move the averages.'],
        ], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $year = (int) ($arguments['year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('n') < 3 ? (int) date('Y') - 1 : (int) date('Y');
        }
        $from = $year . '-01-01';
        $to   = $year . '-12-31';
        $asOf = min($to, date('Y-m-d'));

        $pl      = $this->reports->profitLoss($userId, $from, $to, $this->mileage->businessDeduction($userId, $from, $to));
        $balance = $this->sheet->asOf($userId, $asOf, $this->settings->openingBalance($userId), $this->items->inForce($userId, $asOf));
        $sections = [
            ['title' => 'Resultatopgørelse ' . $year, 'rows' => $pl],
            ['title' => 'Balance pr. ' . \App\Support\Kr::date($asOf), 'rows' => $balance['rows']],
            ['title' => 'Moms pr. kvartal ' . $year, 'rows' => $this->reports->momsQuarters($userId, $year)],
            ['title' => 'Fakturaer pr. kunde ' . $year, 'rows' => $this->reports->customers($userId, $from, $to)],
            ['title' => 'Fakturaoversigt ' . $year, 'rows' => $this->reports->invoices($userId, $from, $to)],
            ['title' => 'Saldobalance ' . $year, 'rows' => $this->ledger->trialBalance($userId, $from, $to)],
            ['title' => 'Kassekladde ' . $year, 'rows' => $this->ledger->table($userId, $from, $to)],
        ];
        if (!empty($arguments['include_budget'])) {
            $b = $this->reports->budget($userId, $year . '-12-01', (float) ($arguments['growth_pct'] ?? 0));
            $sections[] = ['title' => 'Budget — de næste 12 måneder (fremskrivning af ' . $year . ')', 'rows' => $b['rows']];
        }
        $sections = array_values(array_filter($sections, static fn (array $s): bool => $s['rows'] !== []));

        $out = [
            'periode' => (string) $year, 'aar' => $year,
            'noegletal' => ['egenkapital' => $balance['equity'], 'aktiver' => $balance['assets'], 'gaeld' => $balance['liabilities'], 'bank' => $balance['bank_source']],
            'afsnit' => array_map(static fn (array $s): string => $s['title'] . ' (' . count($s['rows']) . ')', $sections),
        ];
        if (!empty($arguments['_export'])) {   // only the export needs the rows; the model just gets the key figures
            $out['sections'] = $sections;
        }

        return $out;
    }
}
