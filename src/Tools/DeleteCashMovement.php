<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Cash;
use App\Data\CashEntries;
use App\Data\ChangeLog;

/**
 * Tool: remove a wrongly recorded bank movement (a moms payment entered twice, a bank fee that was not one). Only the manual movements of the cash view —
 * invoices, expenses and owner draws have their own tools. Not part of the legal bookkeeping (see CashEntries), so there is no audit trail; the reply says
 * exactly what was removed, so it can be put back with record_cash_movement.
 */
final class DeleteCashMovement implements Tool
{
    public function __construct(private CashEntries $entries, private Cash $cash, private ?ChangeLog $log = null)
    {
    }

    public function name(): string
    {
        return 'delete_cash_movement';
    }

    public function description(): string
    {
        return 'Deletes ONE manual bank movement that was recorded by mistake (a moms payment entered twice, a wrong fee) — "delete that moms payment", "slet den dobbelte momsbetaling". '
            . 'Needs the movement\'s `id`: take it from get_cash (its movements list), and if more than one could be meant, ask which. To CHANGE an amount/date/note use update_cash_movement instead. '
            . 'Not for invoices (use their own tools), expenses or owner draws. Say plainly what was removed.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'The movement id, from get_cash → movements.']],
            'required'   => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id  = (int) ($arguments['id'] ?? 0);
        $was = $id > 0 ? $this->entries->get($userId, $id) : null;
        if ($was === null) {
            return ['deleted' => false, 'error' => 'No such cash movement (check the id with get_cash).'];
        }
        $this->entries->delete($userId, $id);
        $logId = $this->log?->record($userId, 'cash_movement', $id, 'delete', 'Deleted ' . $was['direction'] . ' ' . $was['amount'] . ' kr ' . $was['category'] . ' ' . $was['date'], $was, null, $this->name());
        $card = $this->cash->position($userId);

        return ['deleted' => true, 'change_id' => $logId, 'removed' => $was, 'expected' => $card['expected'], 'free_to_spend' => $card['free_to_spend'], '_render' => $card];
    }
}
