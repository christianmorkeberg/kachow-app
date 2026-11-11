<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ShoppingLists;

/**
 * Tool: rename a shared list ("call the Groceries list Weekly shop"). Items stay; the Lists card shows the result.
 */
final class RenameShoppingList implements Tool
{
    public function __construct(private Connections $connections, private ShoppingLists $lists)
    {
    }

    public function name(): string
    {
        return 'rename_shopping_list';
    }

    public function description(): string
    {
        return 'Renames one of the shared lists (shopping list, packing list, to-do …) — "call the Groceries list Weekly shop", "omdøb pakkelisten til Ferie". The items stay on it. '
            . 'Fails if another list already has that name.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'list'     => ['type' => 'string', 'description' => 'The list\'s current name.'],
                'new_name' => ['type' => 'string', 'description' => 'The new name.'],
                'person'   => ['type' => 'string', 'description' => 'Only needed if you share lists with more than one person.'],
            ],
            'required' => ['list', 'new_name'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = HouseholdAccess::resolve($this->connections, $userId, $arguments['person'] ?? null);
        if (isset($access['error'])) {
            return $access;
        }
        $cid  = (int) $access['connection_id'];
        $name = trim((string) ($arguments['list'] ?? ''));
        $row  = $this->lists->findByName($cid, $name);
        if ($row === null) {
            return ['error' => "There's no list called \"{$name}\"."];
        }
        $r = $this->lists->rename($cid, (int) $row['id'], (string) ($arguments['new_name'] ?? ''));
        if ($r === 'exists') {
            return ['error' => 'There is already a list with that name.'];
        }
        if ($r !== true) {
            return ['error' => 'What should the list be called?'];
        }

        return ['renamed' => true, 'from' => (string) $row['name'], 'to' => trim((string) $arguments['new_name']), '_render' => $this->lists->overviewCard($cid)];
    }
}
