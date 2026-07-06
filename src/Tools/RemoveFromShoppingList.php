<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ShoppingLists;

/**
 * Tool: remove an item from a shared shopping list entirely.
 */
final class RemoveFromShoppingList implements Tool
{
    public function __construct(private Connections $connections, private ShoppingLists $lists)
    {
    }

    public function name(): string
    {
        return 'remove_from_shopping_list';
    }

    public function description(): string
    {
        return 'Removes an item from a shared shopping list entirely (different from checking it '
            . 'off). Match the item text from get_shopping_list. Omit "list" for the default list.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'item'   => ['type' => 'string', 'description' => 'The item to remove.'],
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
            return ['error' => 'Which item should I remove?'];
        }
        $list = $this->lists->resolve((int) $access['connection_id'], $arguments['list'] ?? null, $userId, false);
        if (isset($list['error'])) {
            return $list;
        }

        $n = $this->lists->removeItem((int) $list['id'], $item);

        return $n > 0
            ? [
                'removed' => $item,
                'list'    => $list['name'],
                '_render' => $this->lists->cardForList((int) $access['connection_id'], (int) $list['id'], (string) $list['name']),
            ]
            : ['error' => "\"{$item}\" isn't on the {$list['name']} list."];
    }
}
