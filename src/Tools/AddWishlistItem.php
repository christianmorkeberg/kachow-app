<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Wishlist;
use App\Support\TextMatch;

/**
 * Tool: add a wishlist item. Thin wrapper over Data\Wishlist::add.
 */
final class AddWishlistItem implements Tool
{
    public function __construct(private Wishlist $wishlist)
    {
    }

    public function name(): string
    {
        return 'add_wishlist_item';
    }

    public function description(): string
    {
        return "Adds an item to the user's wishlist. Use when the user says they want something, "
            . 'wish they had something, or asks to note something down to buy/get later.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'item' => [
                    'type'        => 'string',
                    'description' => 'The thing the user wants, e.g. "Sony WH-1000XM5 headphones".',
                ],
                'category' => [
                    'type'        => 'string',
                    'description' => 'Optional grouping, e.g. "electronics", "books", "kitchen".',
                ],
                'url' => [
                    'type'        => 'string',
                    'description' => 'Optional link to the product.',
                ],
                'price' => [
                    'type'        => 'number',
                    'description' => 'Optional approximate price.',
                ],
                'priority' => [
                    'type'        => 'integer',
                    'description' => 'Optional priority from 1 (highest) to 5 (lowest).',
                ],
                'notes' => [
                    'type'        => 'string',
                    'description' => 'Optional free-form note.',
                ],
            ],
            'required' => ['item'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $item = trim((string) ($arguments['item'] ?? ''));
        if ($item === '') {
            return ['error' => 'An item name is required.'];
        }

        // Block same-language duplicates; surface the rest so the model can catch a
        // reworded / other-language equivalent before duplicating.
        $existing = $this->wishlist->all($userId);
        foreach ($existing as $e) {
            if (TextMatch::similar($item, (string) $e['item'])) {
                return [
                    'added'     => false,
                    'duplicate' => true,
                    'matched'   => ['id' => (int) $e['id'], 'item' => (string) $e['item']],
                    'message'   => 'That is already on the wishlist (as "' . $e['item'] . '") — tell the '
                        . 'user instead of adding a duplicate.',
                ];
            }
        }

        $id = $this->wishlist->add(
            $userId,
            $item,
            isset($arguments['category']) && $arguments['category'] !== '' ? (string) $arguments['category'] : null,
            isset($arguments['url']) && $arguments['url'] !== '' ? (string) $arguments['url'] : null,
            isset($arguments['price']) && $arguments['price'] !== '' ? (float) $arguments['price'] : null,
            isset($arguments['priority']) && $arguments['priority'] !== '' ? (int) $arguments['priority'] : null,
            isset($arguments['notes']) && $arguments['notes'] !== '' ? (string) $arguments['notes'] : null,
        );

        return [
            'added'          => true,
            'id'             => $id,
            'item'           => $item,
            'existing_items' => array_map(static fn (array $e): string => (string) $e['item'], $existing),
            'dedupe_hint'    => 'If `item` matches any of existing_items reworded or in another language, '
                . 'it is a duplicate — call the wishlist remove/delete tool with id=' . $id . ' and tell '
                . 'the user it is already there.',
        ];
    }
}
