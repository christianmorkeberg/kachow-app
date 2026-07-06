<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ShoppingLists;

/**
 * Tool: check off (mark as got) an item on a shared shopping list.
 */
final class CheckOffItem implements Tool
{
    public function __construct(private Connections $connections, private ShoppingLists $lists)
    {
    }

    public function name(): string
    {
        return 'check_off_item';
    }

    public function description(): string
    {
        return 'Marks an item on a shared shopping list as bought/done (checks it off). Match the '
            . "item text as shown by get_shopping_list. Omit \"list\" for the default list.";
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'item'   => ['type' => 'string', 'description' => 'The item to check off, e.g. "milk".'],
                'list'   => ['type' => 'string', 'description' => 'Optional list name. Omit for the default list.'],
                'person' => ['type' => 'string', 'description' => 'Only needed if you share lists with more than one person.'],
            ],
            'required' => ['item'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = HouseholdAccess::resolve($this->connections, $userId, $arguments['person'] ?? null);
        if (isset($access['error'])) {
            return $access;
        }
        $item = trim((string) ($arguments['item'] ?? ''));
        if ($item === '') {
            return ['error' => 'Which item should I check off?'];
        }
        $list = $this->lists->resolve((int) $access['connection_id'], $arguments['list'] ?? null, $userId, false);
        if (isset($list['error'])) {
            return $list;
        }

        $n = $this->lists->setChecked((int) $list['id'], $item, true, $userId);

        return $n > 0
            ? [
                'checked_off' => $item,
                'list'        => $list['name'],
                '_render'     => $this->lists->cardForList((int) $access['connection_id'], (int) $list['id'], (string) $list['name']),
            ]
            : ['error' => "\"{$item}\" isn't on the {$list['name']} list."];
    }
}
