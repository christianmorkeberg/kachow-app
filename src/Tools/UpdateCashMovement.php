<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Cash;
use App\Data\CashEntries;
use App\Data\ChangeLog;

/** Tool: correct a recorded bank movement (amount, date, direction, category, note) — only what is given changes. */
final class UpdateCashMovement implements Tool
{
    public function __construct(private CashEntries $entries, private Cash $cash, private ?ChangeLog $log = null)
    {
    }

    public function name(): string
    {
        return 'update_cash_movement';
    }

    public function description(): string
    {
        return 'Corrects ONE manual bank movement: a wrong amount, date, direction (in/out), category (moms, tax, fee, deposit, other) or note — "the moms payment was 3540, not 3450", "ret datoen på momsbetalingen". '
            . 'Needs the movement\'s `id` from get_cash (its movements list); give only the fields that change. To remove it use delete_cash_movement. Not for invoices, expenses or owner draws.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'        => ['type' => 'integer', 'description' => 'The movement id, from get_cash → movements.'],
                'amount'    => ['type' => 'number', 'description' => 'New amount in DKK, positive.'],
                'direction' => ['type' => 'string', 'enum' => ['in', 'out']],
                'category'  => ['type' => 'string', 'enum' => CashEntries::CATEGORIES],
                'note'      => ['type' => 'string'],
                'date'      => ['type' => 'string', 'description' => 'New date YYYY-MM-DD.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id   = (int) ($arguments['id'] ?? 0);
        $was  = $id > 0 ? $this->entries->get($userId, $id) : null;
        if ($was === null) {
            return ['updated' => false, 'error' => 'No such cash movement (check the id with get_cash).'];
        }
        $now  = $this->entries->update($userId, $id, $arguments);
        $logId = $this->log?->record($userId, 'cash_movement', $id, 'update', 'Changed ' . $was['category'] . ' ' . $was['date'] . ': ' . $was['amount'] . ' → ' . ($now['amount'] ?? '?') . ' kr', $was, $now, $this->name());
        $card = $this->cash->position($userId);

        return ['updated' => true, 'change_id' => $logId, 'was' => $was, 'now' => $now, 'expected' => $card['expected'], 'free_to_spend' => $card['free_to_spend'], '_render' => $card];
    }
}
