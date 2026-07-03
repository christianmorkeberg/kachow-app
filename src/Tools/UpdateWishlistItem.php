<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Wishlist;

/**
 * Tool: edit a wishlist item in place. Thin wrapper over Data\Wishlist::update.
 */
final class UpdateWishlistItem implements Tool
{
    public function __construct(private Wishlist $wishlist)
    {
    }

    public function name(): string
    {
        return 'update_wishlist_item';
    }

    public function description(): string
    {
        return 'Edits an existing wishlist item. First call get_wishlist to find the item and its id, '
            . 'then pass the id and only the fields to change (item, category, url, price, priority, '
            . 'notes). Use to fix or update an item, e.g. change its price or priority.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'       => ['type' => 'integer', 'description' => 'Id of the item (from get_wishlist).'],
                'item'     => ['type' => 'string', 'description' => 'New name for the item.'],
                'category' => ['type' => 'string', 'description' => 'New category.'],
                'url'      => ['type' => 'string', 'description' => 'New URL.'],
                'price'    => ['type' => 'number', 'description' => 'New price.'],
                'priority' => ['type' => 'integer', 'description' => 'New priority (1 highest .. 5 lowest).'],
                'notes'    => ['type' => 'string', 'description' => 'New note.'],
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

        $fields = [];
        if (isset($arguments['item']) && $arguments['item'] !== '') {
            $fields['item'] = (string) $arguments['item'];
        }
        foreach (['category', 'url', 'notes'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $fields[$key] = $arguments[$key] !== '' ? (string) $arguments[$key] : null;
            }
        }
        if (isset($arguments['price']) && $arguments['price'] !== '') {
            $fields['price'] = (float) $arguments['price'];
        }
        if (isset($arguments['priority']) && $arguments['priority'] !== '') {
            $fields['priority'] = (int) $arguments['priority'];
        }

        if ($fields === []) {
            return ['error' => 'Specify at least one field to change (item, category, url, price, priority, notes).'];
        }

        $ok = $this->wishlist->update($userId, $id, $fields);

        return $ok ? ['updated' => true, 'id' => $id] : ['updated' => false, 'error' => 'No matching item found.'];
    }
}
