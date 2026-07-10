<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\DevIdeas;

/**
 * Tool: list the user's captured app-development ideas.
 */
final class ListDevIdeas implements Tool
{
    public function __construct(private DevIdeas $ideas)
    {
    }

    public function name(): string
    {
        return 'list_dev_ideas';
    }

    public function description(): string
    {
        return 'Lists the ideas the user has saved for developing the app further (their dev backlog). '
            . 'Use when they ask what ideas/features they have noted for the app. Each idea has an id '
            . '(needed by remove_dev_idea).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $ideas = array_map(static function (array $i): array {
            $ts = strtotime($i['created_at']);

            return ['id' => $i['id'], 'idea' => $i['idea'], 'noted' => $ts !== false ? date('j M', $ts) : ''];
        }, $this->ideas->listForUser($userId));

        return ['count' => count($ideas), 'ideas' => $ideas];
    }
}
