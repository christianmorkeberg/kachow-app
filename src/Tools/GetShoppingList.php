<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ShoppingLists;

/**
 * Tool: read the items on a shared shopping list.
 */
final class GetShoppingList implements Tool
{
    public function __construct(private Connections $connections, private ShoppingLists $lists)
    {
    }

    public function name(): string
    {
        return 'get_shopping_list';
    }

    public function description(): string
    {
        return 'Shows the items on a shared shopping list, marking which are already checked off '
            . 'and who added each. Omit "list" for the everyday default list, or name one. Use '
            . 'list_shopping_lists first if you are not sure which lists exist.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'list'   => ['type' => 'string', 'description' => 'Optional list name. Omit for the default list.'],
                'person' => ['type' => 'string', 'description' => 'Only needed if you share lists with more than one person.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = HouseholdAccess::resolve($this->connections, $userId, $arguments['person'] ?? null);
        if (isset($access['error'])) {
            return $access;
        }

        $list = $this->lists->resolve((int) $access['connection_id'], $arguments['list'] ?? null, $userId, false);
        if (isset($list['error'])) {
            return $list;
        }

        $items = $this->lists->items((int) $list['id']);

        return [
            'list'      => $list['name'],
            'count'     => count($items),
            'remaining' => count(array_filter($items, static fn (array $i): bool => !$i['checked'])),
            'items'     => $items,
        ];
    }
}
