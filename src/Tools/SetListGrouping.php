<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ShoppingLists;
use App\Support\ListCategories;

/**
 * Tool: turn a shared list's automatic category grouping on or off.
 */
final class SetListGrouping implements Tool
{
    public function __construct(private Connections $connections, private ShoppingLists $lists)
    {
    }

    public function name(): string
    {
        return 'set_list_grouping';
    }

    public function description(): string
    {
        return 'Turns automatic category grouping on or off for one named shared list (groceries group '
            . 'into aisles, a packing list into clothing/electronics/etc. by default). Use this when the '
            . 'user says a list should NOT be grouped/categorised — e.g. a to-do, ideas, or project list '
            . 'where the items have nothing to do with each other — or asks to turn grouping back on. '
            . 'Omit "list" for the default list.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'list'    => ['type' => 'string', 'description' => 'The list name. Omit for the default list.'],
                'grouped' => [
                    'type'        => 'boolean',
                    'description' => 'false = always show this list flat, never grouped into categories. '
                        . 'true = group it automatically again (the default).',
                ],
                'person'  => ['type' => 'string', 'description' => 'Only needed if you share lists with more than one person.'],
            ],
            'required' => ['grouped'],
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

        $grouped = (bool) ($arguments['grouped'] ?? true);
        $mode    = $grouped ? ListCategories::MODE_AUTO : ListCategories::MODE_NONE;
        $this->lists->setGrouping((int) $access['connection_id'], (int) $list['id'], $mode);

        return [
            'list'    => $list['name'],
            'grouped' => $grouped,
            '_render' => $this->lists->cardForList((int) $access['connection_id'], (int) $list['id'], (string) $list['name']),
        ];
    }
}
