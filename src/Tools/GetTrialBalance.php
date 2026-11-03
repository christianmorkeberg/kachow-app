<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Ledger;

/** Tool: the saldobalance — totals per account for a period, in Danish. */
final class GetTrialBalance implements Tool
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function name(): string
    {
        return 'get_trial_balance';
    }

    public function description(): string
    {
        return 'The SALDOBALANCE: totals per account (Indtægter, Udgifter, Privat, Bank) for a period — count, ex-moms, moms and total. Use for "saldobalance", '
            . '"kontospecifikation", "totals per account", "hvad har jeg brugt per kategori". To give the user a file call export_data with source get_trial_balance '
            . '(language da).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => BookkeepingRange::PARAMS, 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        [$from, $to, $label] = BookkeepingRange::resolve($arguments);

        return ['periode' => $label, 'konti' => $this->ledger->trialBalance($userId, $from, $to)];
    }
}
