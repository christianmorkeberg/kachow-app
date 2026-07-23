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

        // cardForList also auto-purges stale checked items, so derive from it.
        $card = $this->lists->cardForList((int) $access['connection_id'], (int) $list['id'], (string) $list['name']);

        // Give the model the item NAMES (not just counts) so it can actually reason
        // about the list — e.g. suggest recipes — but the card is the display, so tell
        // it not to just re-list them as text.
        return [
            'list'      => $list['name'],
            'count'     => count($card['items']),
            'remaining' => count(array_filter($card['items'], static fn (array $i): bool => !$i['done'])),
            'items'     => array_map(
                static fn (array $i): array => ['name' => $i['label'], 'done' => $i['done']],
                $card['items']
            ),
            'note'      => 'The card already displays these items to the user. Use the names to answer '
                . 'their question (e.g. recipe ideas, what\'s missing), but do NOT just re-list the items '
                . 'as text — the card shows them.',
            '_render'   => $card,
        ];
    }
}
