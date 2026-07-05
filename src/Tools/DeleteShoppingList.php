<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ShoppingLists;

/**
 * Tool: delete a whole shared shopping list (and its items).
 */
final class DeleteShoppingList implements Tool
{
    public function __construct(private Connections $connections, private ShoppingLists $lists)
    {
    }

    public function name(): string
    {
        return 'delete_shopping_list';
    }

    public function description(): string
    {
        return 'Deletes an entire named shared shopping list and everything on it — e.g. the '
            . '"birthday" list once the party is over. Requires the list name (the everyday default '
            . 'list is best cleared, not deleted). This cannot be undone.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'list'   => ['type' => 'string', 'description' => 'Name of the list to delete.'],
                'person' => ['type' => 'string', 'description' => 'Only needed if you share lists with more than one person.'],
            ],
            'required' => ['list'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = HouseholdAccess::resolve($this->connections, $userId, $arguments['person'] ?? null);
        if (isset($access['error'])) {
            return $access;
        }
        $name = trim((string) ($arguments['list'] ?? ''));
        if ($name === '') {
            return ['error' => 'Which list should I delete?'];
        }

        $row = $this->lists->findByName((int) $access['connection_id'], $name);
        if ($row === null) {
            return ['error' => "There's no shopping list called \"{$name}\"."];
        }

        $this->lists->delete((int) $access['connection_id'], (int) $row['id']);

        return ['deleted_list' => (string) $row['name']];
    }
}
