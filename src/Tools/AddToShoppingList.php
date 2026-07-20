<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ShoppingLists;
use App\Support\TextMatch;

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

        // What's already on the list (before we add), so we can skip duplicates and let
        // the model catch cross-language / reworded ones ("milk" vs "mælk").
        $before   = $this->lists->cardForList((int) $access['connection_id'], (int) $list['id'], (string) $list['name']);
        $existing = array_map(static fn (array $i): string => (string) $i['label'], $before['items'] ?? []);
        $seen     = array_map([TextMatch::class, 'normalize'], $existing);

        $added = [];
        $skipped = [];
        foreach ($items as $item) {
            $norm = TextMatch::normalize($item);
            if (in_array($norm, $seen, true)) {
                $skipped[] = $item; // already on the list (same-language)
                continue;
            }
            $this->lists->addItem((int) $list['id'], $item, $userId);
            $added[] = $item;
            $seen[]  = $norm;
        }

        return [
            'added'           => $added,
            'already_present' => $skipped,
            'existing_items'  => $existing,
            'list'            => $list['name'],
            'list_created'    => $list['created'] ?? false,
            'dedupe_hint'     => 'Items in already_present were skipped (already on the list). Also check '
                . 'existing_items for the same thing reworded or in another language (e.g. "milk"/"mælk") '
                . 'and tell the user rather than adding a duplicate. If nothing was actually added, say so.',
            '_render'         => $this->lists->cardForList((int) $access['connection_id'], (int) $list['id'], (string) $list['name']),
        ];
    }
}
