<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Vinyls;

/**
 * Tool: list/browse the vinyl collection with optional filters.
 */
final class GetVinyls implements Tool
{
    public function __construct(private Vinyls $vinyls)
    {
    }

    public function name(): string
    {
        return 'get_vinyls';
    }

    public function description(): string
    {
        return "Lists the user's vinyl records, highest-rated first. Filter by genre or style, by "
            . 'whether they have listened to it yet (unheard_only), or by minimum rating. Use for '
            . 'questions like "what unheard jazz do I have?" or "show my favourite records".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'genre'        => ['type' => 'string', 'description' => 'Filter by genre or style (matches either), e.g. "jazz", "modal".'],
                'unheard_only' => ['type' => 'boolean', 'description' => 'True to show only records not yet listened to.'],
                'min_rating'   => ['type' => 'integer', 'description' => 'Only records rated at least this (1-5).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $genre = isset($arguments['genre']) && $arguments['genre'] !== '' ? (string) $arguments['genre'] : null;
        $heard = array_key_exists('unheard_only', $arguments) && $arguments['unheard_only'] ? false : null;
        $minRating = isset($arguments['min_rating']) && $arguments['min_rating'] !== '' ? (int) $arguments['min_rating'] : null;

        $vinyls = $this->vinyls->all($userId, $genre, $heard, $minRating);

        return ['count' => count($vinyls), 'vinyls' => $vinyls];
    }
}
