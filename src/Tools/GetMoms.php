<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Moms;

/**
 * Tool: the quarterly moms (VAT) settlement card — salgsmoms − købsmoms = tilsvar for
 * a quarter, with the filing deadline. Renders the interactive `moms` card (page between
 * quarters via api/moms.php). Distinct from get_books (the whole cockpit): this is the
 * focused momsafregning the user files with SKAT.
 */
final class GetMoms implements Tool
{
    public function __construct(private Moms $moms)
    {
    }

    public function name(): string
    {
        return 'get_moms';
    }

    public function description(): string
    {
        return 'Shows the quarterly MOMS (Danish VAT) settlement: salgsmoms (output VAT on invoices) minus '
            . 'købsmoms (input VAT on expenses) = tilsvar (to pay SKAT, or reclaim), plus the filing '
            . 'deadline. Use for "how much moms do I owe", "moms this quarter", "momsafregning", '
            . '"momsangivelse", "vat return", "when is moms due", "hvor meget moms skal jeg betale". '
            . 'Defaults to the current quarter; can page to previous quarters within the card. For the '
            . 'whole financial picture use get_books instead.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => [
                    'type'        => 'string',
                    'enum'        => ['this_quarter', 'last_quarter'],
                    'description' => 'Which quarter. Defaults to this_quarter. "last_quarter" is usually the '
                        . 'one currently being filed. The user can page to earlier quarters in the card.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $offset = ((string) ($arguments['period'] ?? 'this_quarter')) === 'last_quarter' ? -1 : 0;
        $card   = $this->moms->card($userId, $offset);

        return [
            'period'    => $card['label'],
            'salgsmoms' => $card['salgsmoms'],
            'kobsmoms'  => $card['kobsmoms'],
            'tilsvar'   => $card['tilsvar'],
            'pay'       => $card['pay'],
            'deadline'  => $card['deadline'],
            'days_left' => $card['days_left'],
            '_render'   => $card,
        ];
    }
}
