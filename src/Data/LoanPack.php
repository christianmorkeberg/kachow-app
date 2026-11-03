<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\DataExport;
use App\Support\Kr;

/**
 * The loan pack (lånepakke / regnskabspakke) of one calendar year: the statements as titled sections, the key figures, the hand-entered balance items in force,
 * and the renderable card (kind: loan_pack) with links to the PDF / CSV / bilag ZIP. One place builds it for the card, the chat tool and the export.
 */
final class LoanPack
{
    public function __construct(
        private Ledger $ledger,
        private LoanReports $reports,
        private BalanceSheet $sheet,
        private BalanceItems $items,
        private UserSettings $settings,
        /** @var callable(int, string, string): float business driving deduction for a period (Mileage::businessDeduction) */
        private $mileageDeduction,
    ) {
    }

    /** Last full year before March, otherwise this year — the year a bank most likely asks about. */
    public static function defaultYear(): int
    {
        return (int) date('n') < 3 ? (int) date('Y') - 1 : (int) date('Y');
    }

    /**
     * @return array{year:int, as_of:string, sections:list<array{id:string,title:string,rows:list<array<string,string>>}>, key:array<string,string>, items:list<array<string,mixed>>, equity:float, assets:float, liabilities:float, bank_source:string}
     */
    public function build(int $userId, int $year, bool $budget = false, float $growthPct = 0.0): array
    {
        $from = $year . '-01-01';
        $to   = $year . '-12-31';
        $asOf = min($to, date('Y-m-d'));

        $items   = $this->items->inForce($userId, $asOf);
        $pl      = $this->reports->profitLoss($userId, $from, $to, ($this->mileageDeduction)($userId, $from, $to));
        $balance = $this->sheet->asOf($userId, $asOf, $this->settings->openingBalance($userId), $items);
        $sections = [
            ['id' => 'result', 'title' => 'Resultatopgørelse ' . $year, 'rows' => $pl],
            ['id' => 'balance', 'title' => 'Balance pr. ' . Kr::date($asOf), 'rows' => $balance['rows']],
            ['id' => 'moms', 'title' => 'Moms pr. kvartal ' . $year, 'rows' => $this->reports->momsQuarters($userId, $year)],
            ['id' => 'customers', 'title' => 'Fakturaer pr. kunde ' . $year, 'rows' => $this->reports->customers($userId, $from, $to)],
            ['id' => 'invoices', 'title' => 'Fakturaoversigt ' . $year, 'rows' => $this->reports->invoices($userId, $from, $to)],
            ['id' => 'trial', 'title' => 'Saldobalance ' . $year, 'rows' => $this->ledger->trialBalance($userId, $from, $to)],
            ['id' => 'ledger', 'title' => 'Kassekladde ' . $year, 'rows' => $this->ledger->table($userId, $from, $to)],
        ];
        if ($budget) {
            $b = $this->reports->budget($userId, $year . '-12-01', $growthPct);
            $sections[] = ['id' => 'budget', 'title' => 'Budget — de næste 12 måneder (fremskrivning af ' . $year . ')', 'rows' => $b['rows']];
        }

        $byPost = array_column($pl, 'beloeb', 'post');
        $bal    = array_column($balance['rows'], 'beloeb', 'post');

        return [
            'year' => $year, 'as_of' => $asOf,
            'sections' => array_values(array_filter($sections, static fn (array $s): bool => $s['rows'] !== [])),
            'key' => [
                'omsaetning' => $byPost['Omsætning'] ?? '0,00', 'resultat' => $byPost['Resultat før skat'] ?? '0,00',
                'egenkapital' => Kr::amount($balance['equity']), 'udestaaende' => $bal['Tilgodehavender (udestående fakturaer)'] ?? '0,00',
            ],
            'items' => $items, 'equity' => $balance['equity'], 'assets' => $balance['assets'], 'liabilities' => $balance['liabilities'], 'bank_source' => $balance['bank_source'],
        ];
    }

    /**
     * The card: sections shaped as tables (Danish headings), key figures, balance items, and download links.
     *
     * @return array<string, mixed>
     */
    public function card(int $userId, int $year): array
    {
        $p        = $this->build($userId, $year, true);
        $sections = [];
        foreach ($p['sections'] as $s) {
            $t = DataExport::table(['rows' => $s['rows']]);
            if ($t === null) {
                continue;
            }
            $shaped     = DataExport::shape($t, [], 'da');
            $sections[] = ['id' => $s['id'], 'title' => $s['title'], 'columns' => $shaped['columns'], 'labels' => $shaped['labels'], 'rows' => $shaped['rows']];
        }
        $export = static fn (string $format, bool $budget): string => '/api/export.php?spec=' . rawurlencode((string) json_encode(
            ['source' => 'get_loan_pack', 'args' => ['year' => $year] + ($budget ? ['include_budget' => true] : []), 'lang' => 'da', 'title' => 'Regnskabspakke ' . $year],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )) . '&format=' . $format;

        return [
            'kind' => 'loan_pack', 'title' => 'Regnskabspakke ' . $year, 'year' => $year, 'as_of' => $p['as_of'], 'can_next' => $year < (int) date('Y'),
            'key' => $p['key'], 'sections' => $sections, 'bank_source' => $p['bank_source'],
            'items' => array_map(static fn (array $i): array => ['kind' => $i['kind'], 'label' => $i['label'], 'amount' => $i['amount'], 'as_of' => $i['as_of']], $p['items']),
            'pdf_url' => $export('pdf', false), 'csv_url' => $export('csv', false), 'print_url' => $export('html', false), 'zip_url' => '/api/bilag-zip.php?year=' . $year,
        ];
    }
}
