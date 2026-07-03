<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\Wishlist;

/**
 * Tool: read a connected person's wishlist — only if they share it with you.
 */
final class GetConnectedWishlist implements Tool
{
    public function __construct(
        private Connections $connections,
        private Wishlist $wishlist,
    ) {
    }

    public function name(): string
    {
        return 'get_connected_wishlist';
    }

    public function description(): string
    {
        return 'Gets the wishlist of a person you are connected with, but only if they share their '
            . 'wishlist with you. Identify them by email or name. Useful for gift ideas — e.g. "what '
            . 'is on Alex\'s wishlist?".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person'   => ['type' => 'string', 'description' => 'Email or name of the connected person.'],
                'category' => ['type' => 'string', 'description' => 'Filter to a category. Omit for all.'],
            ],
            'required' => ['person'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = ConnectionAccess::resolve($this->connections, $userId, (string) ($arguments['person'] ?? ''), 'wishlist');
        if (isset($access['error'])) {
            return $access;
        }

        $category = isset($arguments['category']) && $arguments['category'] !== '' ? (string) $arguments['category'] : null;
        $items = $this->wishlist->all($access['owner_id'], $category);

        return ['person' => $access['person'], 'count' => count($items), 'items' => $items];
    }
}
