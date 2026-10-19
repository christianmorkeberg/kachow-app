<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\DevIdeas;
use App\Data\Users;

/**
 * Tool: list the app-development backlog, with each idea's status, and show it as the
 * dev-ideas card. The developer (admin) sees EVERY user's ideas with who suggested each;
 * anyone else sees the ideas they suggested themselves.
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
        return 'Lists the app\'s dev backlog — ideas saved for developing the app further — and shows them as '
            . 'the dev-ideas card, each with a status: new, in_progress (taken into the LLM backlog / being '
            . 'built) or done. For the developer (admin) it lists EVERY user\'s ideas, each with `from` (who '
            . 'suggested it); for anyone else, the ideas they suggested themselves. Use when they ask what '
            . 'ideas/features are noted for the app ("udviklingsideer", "backlog", "what\'s in progress"). '
            . 'status filters the card (default "open" = everything not done). Each idea has an id (used by '
            . 'set_dev_idea_status, update_dev_idea and remove_dev_idea). Ideas can have longer `details` notes and a '
            . 'priority (high ones are listed first); the list only says WHICH ideas have details — pass `ids` to '
            . 'read the full details of those ideas (e.g. "tell me about #12", or to write them out in chat). '
            . 'The card shows the list and has an export button (Markdown download of one, several or all ideas) — '
            . 'summarise, don\'t re-list.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['open', 'new', 'in_progress', 'done', 'all'],
                    'description' => 'Which ideas to show: open (default, not done), new, in_progress, done or all.',
                ],
                'ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'Only these ideas (the #numbers), returned WITH their full details.',
                ],
            ],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $isAdmin = $this->users->isAdmin($userId);
        $all     = $isAdmin ? $this->ideas->listAll() : $this->ideas->listForUser($userId);
        $filter  = (string) ($arguments['status'] ?? 'open');
        $ids     = array_values(array_filter(array_map('intval', (array) ($arguments['ids'] ?? [])), static fn (int $i): bool => $i > 0));
        $card    = DevIdeas::card($all, $isAdmin, $userId, $ids !== [] ? 'all' : $filter);

        $shown = array_values(array_filter($all, static fn (array $i): bool => $ids !== []
            ? in_array($i['id'], $ids, true)
            : match ($card['filter']) {
                'all'   => true,
                'open'  => $i['status'] !== 'done',
                default => $i['status'] === $card['filter'],
            }));
        $withDetails = $ids !== [];
        $ideas = array_map(static function (array $i) use ($isAdmin, $userId, $withDetails): array {
            $ts  = strtotime($i['created_at']);
            $row = ['id' => $i['id'], 'idea' => $i['idea'], 'status' => $i['status'], 'noted' => $ts !== false ? date('j M', $ts) : ''];
            if ($i['priority'] === 'high') {
                $row['priority'] = 'high';
            }
            if ($withDetails) {
                $row['details'] = $i['details'];
            } elseif ($i['details'] !== null) {
                $row['has_details'] = true;
            }
            if ($isAdmin) {
                $row['from'] = $i['user_id'] === $userId ? 'you' : $i['from'];
            }

            return $row;
        }, $shown);

        return [
            'count'   => count($ideas),
            'counts'  => $card['counts'],
            'scope'   => $isAdmin ? 'all users (you are the developer)' : 'ideas you suggested',
            'showing' => $card['filter'],
            'ideas'   => $ideas,
            '_render' => $card,
        ];
    }
}
