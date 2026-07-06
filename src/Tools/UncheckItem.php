<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ShoppingLists;

/**
 * Tool: un-check an item on a shared shopping list (put it back as still needed).
 */
final class UncheckItem implements Tool
{
    public function __construct(private Connections $connections, private ShoppingLists $lists)
    {
    }

    public function name(): string
    {
        return 'uncheck_item';
    }

    public function description(): string
    {
        return 'Un-checks an item on a shared shopping list — marks it as still needed after it was '
            . 'checked off. Match the item text from get_shopping_list. Omit "list" for the default list.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'item'   => ['type' => 'string', 'description' => 'The item to un-check.'],
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
            return ['error' => 'Which item should I un-check?'];
        }
        $list = $this->lists->resolve((int) $access['connection_id'], $arguments['list'] ?? null, $userId, false);
        if (isset($list['error'])) {
            return $list;
        }

        $n = $this->lists->setChecked((int) $list['id'], $item, false, $userId);

        return $n > 0
            ? [
                'unchecked' => $item,
                'list'      => $list['name'],
                '_render'   => $this->lists->cardForList((int) $access['connection_id'], (int) $list['id'], (string) $list['name']),
            ]
            : ['error' => "\"{$item}\" isn't on the {$list['name']} list."];
    }
}
