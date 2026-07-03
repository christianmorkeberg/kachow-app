<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Wishlist;

/**
 * Tool: read the wishlist. Thin wrapper over Data\Wishlist::all.
 */
final class GetWishlist implements Tool
{
    public function __construct(private Wishlist $wishlist)
    {
    }

    public function name(): string
    {
        return 'get_wishlist';
    }

    public function description(): string
    {
        return "Retrieves the user's wishlist items, highest priority first, optionally filtered by "
            . 'category. Use when the user asks what is on their wishlist or what they wanted.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'category' => [
                    'type'        => 'string',
                    'description' => 'Filter to a single category. Omit to return everything.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $category = isset($arguments['category']) && $arguments['category'] !== ''
            ? (string) $arguments['category'] : null;

        $items = $this->wishlist->all($userId, $category);

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }
}
