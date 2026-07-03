<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Wishlist;

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
            'added' => true,
            'id'    => $id,
            'item'  => $item,
        ];
    }
}
