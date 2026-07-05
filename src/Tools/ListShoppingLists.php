<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ShoppingLists;

/**
 * Tool: list the shared shopping lists (names + counts) for the user's household.
 */
final class ListShoppingLists implements Tool
{
    public function __construct(private Connections $connections, private ShoppingLists $lists)
    {
    }

    public function name(): string
    {
        return 'list_shopping_lists';
    }

    public function description(): string
    {
        return 'Lists the shared shopping lists you have with your connection, with how many items '
            . 'each has and how many are still needed. Use when the user asks what lists exist, or '
            . 'before adding to / reading a named list to avoid creating a duplicate.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
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

        $lists = $this->lists->lists((int) $access['connection_id']);

        return ['count' => count($lists), 'lists' => $lists];
    }
}
