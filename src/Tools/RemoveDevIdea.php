<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\DevIdeas;
use App\Data\Users;

/**
 * Tool: remove an idea from the dev backlog (e.g. once it's built or dropped).
 */
final class RemoveDevIdea implements Tool
{
    public function __construct(private DevIdeas $ideas, private Users $users)
    {
    }

    public function name(): string
    {
        return 'remove_dev_idea';
    }

    public function description(): string
    {
        return 'Removes an idea from the dev backlog by its id (get the id from list_dev_ideas). '
            . 'Use when the user says an idea is done, built, or no longer wanted. The developer (admin) '
            . 'can remove any idea on the shared backlog; others only ideas they suggested.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The id of the idea to remove.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'A valid idea id is required (from list_dev_ideas).'];
        }

        $removed = $this->users->isAdmin($userId)
            ? $this->ideas->deleteAny($id)
            : $this->ideas->delete($userId, $id);

        return $removed
            ? ['removed' => true]
            : ['removed' => false, 'error' => 'No such idea (it may already be gone).'];
    }
}
