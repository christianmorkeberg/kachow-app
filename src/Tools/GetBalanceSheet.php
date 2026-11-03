<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\BalanceItems;
use App\Data\BalanceSheet;
use App\Data\UserSettings;
use DateTimeImmutable;
use DateTimeZone;

/** Tool: the status / balance sheet pr. a date, in Danish. */
final class GetBalanceSheet implements Tool
{
    public function __construct(private BalanceSheet $sheet, private BalanceItems $items, private UserSettings $settings)
    {
    }

    public function name(): string
    {
        return 'get_balance_sheet';
    }

    public function description(): string
    {
        return 'The BALANCE / status pr. a date for the enkeltmandsvirksomhed: Aktiver (bank, tilgodehavender, hand-entered assets) and Passiver (skyldig moms, '
            . 'udlæg, loans, egenkapital). Use for "balance", "status", "what is my equity", "hvad er min egenkapital", "balance pr. 31/12". as_of defaults to today; '
            . 'for a year-end say YYYY-12-31. Bank comes from the books + opening balance unless the user has entered a stated bank balance (set_balance_item). '
            . 'To give the user a file call export_data with source get_balance_sheet (language da). Tell the user which hand-entered items it used and, if none, '
            . 'that equipment/loans are not included until they are entered.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['as_of' => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Default today.']], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $date = (string) ($arguments['as_of'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : (new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d');
        $items = $this->items->inForce($userId, $date);
        $r     = $this->sheet->asOf($userId, $date, $this->settings->openingBalance($userId), $items);

        return [
            'pr_dato' => $date, 'aktiver' => $r['assets'], 'gaeld' => $r['liabilities'], 'egenkapital' => $r['equity'], 'bank' => $r['bank_source'],
            'haandterede_poster' => count($items), 'poster' => $r['rows'],
        ];
    }
}
