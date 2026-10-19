<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\DevIdeas;
use App\Data\Users;

/**
 * Tool: edit a dev-backlog idea (developer only) — reword it, write or extend its details, or set its
 * priority. Status changes stay in set_dev_idea_status, removal in remove_dev_idea (one job per tool).
 */
final class UpdateDevIdea implements Tool
{
    public function __construct(private DevIdeas $ideas, private Users $users)
    {
    }

    public function name(): string
    {
        return 'update_dev_idea';
    }

    public function description(): string
    {
        return 'Edits one dev-backlog idea by id (developer/admin only): `idea` rewords the headline, `details` '
            . 'sets the longer notes (design, decisions, what is open) — with append=true it ADDS to the existing '
            . 'details instead of replacing them — and `priority` ("high" = next up, listed first, or "normal"). '
            . 'Only what you pass changes. Use for "add to #12: …", "reword #5 to …", "make #7 high priority", '
            . 'Danish "tilføj til #12", "sæt #7 som høj prioritet". Does not change status or delete — use '
            . 'set_dev_idea_status / remove_dev_idea. To read an idea\'s details first, call list_dev_ideas with ids.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'       => ['type' => 'integer', 'description' => 'The idea id (the #number).'],
                'idea'     => ['type' => 'string', 'description' => 'New one-line headline.'],
                'details'  => ['type' => 'string', 'description' => 'The details text (replaces, or is appended when append=true).'],
                'append'   => ['type' => 'boolean', 'description' => 'Add `details` as a new paragraph after the existing ones.'],
                'priority' => ['type' => 'string', 'enum' => array_keys(DevIdeas::PRIORITIES)],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Only the developer can edit dev ideas.'];
        }
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'Which idea? Give its id (the #number).'];
        }
        $idea     = isset($arguments['idea']) ? (string) $arguments['idea'] : null;
        $details  = isset($arguments['details']) ? (string) $arguments['details'] : null;
        $priority = isset($arguments['priority']) ? (string) $arguments['priority'] : null;
        if ($idea === null && $details === null && $priority === null) {
            return ['error' => 'Nothing to change — pass idea, details and/or priority.'];
        }
        if ($priority !== null && !isset(DevIdeas::PRIORITIES[$priority])) {
            return ['error' => 'priority must be one of: ' . implode(', ', array_keys(DevIdeas::PRIORITIES)) . '.'];
        }
        if (!$this->ideas->update($id, $idea, $details, $priority, !empty($arguments['append']))) {
            return ['updated' => false, 'error' => 'No such idea.'];
        }
        $now = $this->ideas->findMany([$id])[0] ?? null;

        return [
            'updated'  => true,
            'id'       => $id,
            'idea'     => $now['idea'] ?? null,
            'priority' => $now['priority'] ?? null,
            'details'  => $now['details'] ?? null,
            '_render'  => DevIdeas::card($this->ideas->listAll(), true, $userId, 'open'),
        ];
    }
}
