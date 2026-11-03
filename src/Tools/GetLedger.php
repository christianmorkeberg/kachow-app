<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Ledger;

/** Tool: the kassekladde (journal) — every booked entry in date order with a voucher number, in Danish. */
final class GetLedger implements Tool
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function name(): string
    {
        return 'get_ledger';
    }

    public function description(): string
    {
        return 'The KASSEKLADDE / bogføringsjournal: every booked invoice, confirmed expense, owner drawing and bank movement in date order with a '
            . 'bilagsnummer (ÅÅÅÅ-NNNN), account, ex-moms / moms / total and whether a bilag (receipt) is attached. Use for "show the ledger", "kassekladde", '
            . '"bogføringsjournal", "alle posteringer", and as the source for exports: to give the user a file call export_data with source get_ledger '
            . '(language da). Danish column names. Drafts are not included — the result says how many entries are listed.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => BookkeepingRange::PARAMS, 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        [$from, $to, $label] = BookkeepingRange::resolve($arguments);
        $rows = $this->ledger->table($userId, $from, $to);

        if (!empty($arguments['_export'])) {   // the export asks for every row
            return ['periode' => $label, 'antal' => count($rows), 'posteringer' => $rows];
        }

        return ['periode' => $label, 'antal' => count($rows), 'posteringer' => array_slice($rows, 0, 60), 'note' => count($rows) > 60 ? 'Only the first 60 are shown here; an export has all of them.' : ''];
    }
}
