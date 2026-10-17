<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\Vinyls;

/**
 * Tool: browse a connected person's vinyl collection — only if they share `vinyls` with the acting user.
 * Useful for gift ideas and "do we already own this?". Private notes and Discogs/cover plumbing stay out.
 */
final class GetConnectedVinyls implements Tool
{
    public function __construct(
        private Connections $connections,
        private Vinyls $vinyls,
    ) {
    }

    public function name(): string
    {
        return 'get_connected_vinyls';
    }

    public function description(): string
    {
        return 'Lists the vinyl records of a person you are connected with, highest-rated first, but only '
            . 'if they share their vinyls with you. Identify them by email or name. Same filters as your own '
            . 'get_vinyls (genre/style, unheard_only, min_rating). Use for "what records does Alex have?", '
            . '"does Alex own this album?", gift ideas, Danish "hvilke plader har Alex?". Read-only. Attribute '
            . 'everything to THEM and never mix it with your own collection.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person'       => ['type' => 'string', 'description' => 'Email or name of the connected person.'],
                'genre'        => ['type' => 'string', 'description' => 'Filter by genre or style (matches either), e.g. "jazz".'],
                'unheard_only' => ['type' => 'boolean', 'description' => 'True to show only records they have not listened to yet.'],
                'min_rating'   => ['type' => 'integer', 'description' => 'Only records they rated at least this (1-5).'],
            ],
            'required' => ['person'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = ConnectionAccess::resolve($this->connections, $userId, (string) ($arguments['person'] ?? ''), 'vinyls');
        if (isset($access['error'])) {
            return $access;
        }

        $genre     = isset($arguments['genre']) && $arguments['genre'] !== '' ? (string) $arguments['genre'] : null;
        $heard     = !empty($arguments['unheard_only']) ? false : null;
        $minRating = isset($arguments['min_rating']) && $arguments['min_rating'] !== '' ? (int) $arguments['min_rating'] : null;

        $rows = array_map(static fn (array $v): array => [
            'artist' => $v['artist'] ?? null,
            'title'  => $v['title'] ?? null,
            'genre'  => $v['genre'] ?? null,
            'style'  => $v['style'] ?? null,
            'year'   => $v['year'] ?? null,
            'heard'  => (bool) ($v['heard'] ?? false),
            'rating' => $v['rating'] ?? null,
        ], $this->vinyls->all((int) $access['owner_id'], $genre, $heard, $minRating));

        return ['person' => $access['person'], 'count' => count($rows), 'vinyls' => $rows];
    }
}
