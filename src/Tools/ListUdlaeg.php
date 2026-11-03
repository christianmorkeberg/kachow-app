<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Receipts;

/** Tool: the full list of udlæg — expenses paid with private money — and whether each has been paid back. */
final class ListUdlaeg implements Tool
{
    public function __construct(private Receipts $receipts)
    {
    }

    public function name(): string
    {
        return 'list_udlaeg';
    }

    public function description(): string
    {
        return 'Opens the EXPENSES card filtered to expenses paid with PRIVATE money: every expense the user paid with PRIVATE money, which are still owed to them and which have been paid back (with the date), with totals per '
            . 'currency. Use for "vis mine udlæg", "hvad skylder firmaet mig", "what does the company owe me", "which expenses did I pay privately", "list my udlæg". '
            . 'The card is interactive (filter, mark paid back), so say in one line what is owed — do not read the list out. To mark ONE as paid back from chat use mark_expense_reimbursed. When replying in English say "paid privately" / "out-of-pocket", never the Danish word "udlæg".';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $list = $this->receipts->udlaegList($userId);
        $card = $this->receipts->expensesCard($userId, null, null, 'Paid privately', null, 'any');
        $card['filter'] = 'owed';   // the card opens on what is still owed; the chips switch to all / repaid

        return ['owed' => $list['owed'], 'repaid' => $list['repaid'], 'items' => $list['items'], '_render' => $card];
    }
}
