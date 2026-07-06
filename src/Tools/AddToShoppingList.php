<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ShoppingLists;

/**
 * Tool: add one or more items to a shared shopping list. Unnamed → the default
 * list; a name → that list, created if it doesn't exist yet.
 */
final class AddToShoppingList implements Tool
{
    public function __construct(private Connections $connections, private ShoppingLists $lists)
    {
    }

    public function name(): string
    {
        return 'add_to_shopping_list';
    }

    public function description(): string
    {
        return 'Adds one or more items to a shared shopping list (shared with the person you are '
            . 'connected with). Use for groceries and errands like "add milk and eggs". Omit "list" '
            . 'for the everyday default list; give a "list" name (e.g. "birthday") to use or start a '
            . 'separate list. This is NOT the personal wishlist.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'items' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'The items to add, e.g. ["milk", "eggs", "bread"].',
                ],
                'list'   => ['type' => 'string', 'description' => 'Optional list name. Omit for the default list.'],
                'person' => ['type' => 'string', 'description' => 'Only needed if you share lists with more than one person.'],
            ],
            'required' => ['items'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = HouseholdAccess::resolve($this->connections, $userId, $arguments['person'] ?? null);
        if (isset($access['error'])) {
            return $access;
        }

        $items = array_values(array_filter(array_map(
            static fn ($i): string => trim((string) $i),
            is_array($arguments['items'] ?? null) ? $arguments['items'] : []
        ), static fn (string $i): bool => $i !== ''));
        if ($items === []) {
            return ['error' => 'Give at least one item to add.'];
        }

        $list = $this->lists->resolve((int) $access['connection_id'], $arguments['list'] ?? null, $userId, true);
        if (isset($list['error'])) {
            return $list;
        }

        foreach ($items as $item) {
            $this->lists->addItem((int) $list['id'], $item, $userId);
        }

        return [
            'added'        => $items,
            'list'         => $list['name'],
            'list_created' => $list['created'] ?? false,
            '_render'      => $this->lists->cardForList((int) $access['connection_id'], (int) $list['id'], (string) $list['name']),
        ];
    }
}
