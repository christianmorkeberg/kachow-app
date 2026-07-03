<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Wishlist;

/**
 * Tool: remove a wishlist item. Thin wrapper over Data\Wishlist::delete.
 */
final class RemoveWishlistItem implements Tool
{
    public function __construct(private Wishlist $wishlist)
    {
    }

    public function name(): string
    {
        return 'delete_wishlist_item';
    }

    public function description(): string
    {
        return 'Removes an item from the wishlist. First call get_wishlist to find the item and its id, '
            . 'then pass that id. Use when the user got something, no longer wants it, or asks to remove it.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Id of the item to remove (from get_wishlist).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = isset($arguments['id']) ? (int) $arguments['id'] : 0;
        if ($id <= 0) {
            return ['error' => 'A valid item id is required (from get_wishlist).'];
        }

        $deleted = $this->wishlist->delete($userId, $id);

        return $deleted ? ['deleted' => true, 'id' => $id] : ['deleted' => false, 'error' => 'No matching item found.'];
    }
}
