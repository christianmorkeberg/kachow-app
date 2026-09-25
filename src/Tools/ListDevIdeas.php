<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\DevIdeas;
use App\Data\Users;

/**
 * Tool: list the app-development backlog. The developer (admin) sees EVERY user's ideas
 * with who suggested each; anyone else sees the ideas they suggested themselves.
 */
final class ListDevIdeas implements Tool
{
    public function __construct(private DevIdeas $ideas, private Users $users)
    {
    }

    public function name(): string
    {
        return 'list_dev_ideas';
    }

    public function description(): string
    {
        return 'Lists the app\'s dev backlog — ideas saved for developing the app further. For the '
            . 'developer (admin) it lists EVERY user\'s ideas, each with `from` (who suggested it); for '
            . 'anyone else, the ideas they suggested themselves. Use when they ask what ideas/features '
            . 'are noted for the app ("udviklingsideer", "backlog"). Each idea has an id (needed by '
            . 'remove_dev_idea).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $isAdmin = $this->users->isAdmin($userId);
        $ideas   = array_map(static function (array $i) use ($isAdmin, $userId): array {
            $ts  = strtotime($i['created_at']);
            $row = ['id' => $i['id'], 'idea' => $i['idea'], 'noted' => $ts !== false ? date('j M', $ts) : ''];
            if ($isAdmin) {
                $row['from'] = $i['user_id'] === $userId ? 'you' : $i['from'];
            }

            return $row;
        }, $isAdmin ? $this->ideas->listAll() : $this->ideas->listForUser($userId));

        return [
            'count' => count($ideas),
            'scope' => $isAdmin ? 'all users (you are the developer)' : 'ideas you suggested',
            'ideas' => $ideas,
        ];
    }
}
