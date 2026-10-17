<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\DevIdeas;
use App\Data\Users;

/**
 * Tool: move dev ideas between new / in_progress / done (developer only), then show the card.
 */
final class SetDevIdeaStatus implements Tool
{
    public function __construct(private DevIdeas $ideas, private Users $users)
    {
    }

    public function name(): string
    {
        return 'set_dev_idea_status';
    }

    public function description(): string
    {
        return 'Changes the status of one or more dev-backlog ideas by id: "new", "in_progress" (taken into the '
            . 'LLM backlog / being built) or "done". Developer (admin) only. Use for "mark #38 done", "#41 and '
            . '#42 are in progress", Danish "sæt #38 til færdig", "marker 41 som i gang". Shows the updated '
            . 'dev-ideas card.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'ids'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Idea ids (the #numbers).'],
                'status' => ['type' => 'string', 'enum' => array_keys(DevIdeas::STATUSES), 'description' => 'The new status.'],
            ],
            'required'   => ['ids', 'status'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Only the developer can change the status of dev ideas.'];
        }
        $status = (string) ($arguments['status'] ?? '');
        if (!isset(DevIdeas::STATUSES[$status])) {
            return ['error' => 'status must be one of: ' . implode(', ', array_keys(DevIdeas::STATUSES)) . '.'];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($arguments['ids'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return ['error' => 'Which idea? Give its id (the #number).'];
        }

        $updated = $missing = [];
        foreach ($ids as $id) {
            if ($this->ideas->setStatus($id, $status)) {
                $updated[] = $id;
            } else {
                $missing[] = $id;
            }
        }

        return [
            'updated' => $updated,
            'status'  => $status,
            'not_found' => $missing,
            '_render' => DevIdeas::card($this->ideas->listAll(), true, $userId, $status === 'done' ? 'all' : 'open'),
        ];
    }
}
