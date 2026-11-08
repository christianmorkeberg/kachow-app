<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Users;
use App\Recruiter\RecruiterLinks;

/** Tool (owner/admin only): what a recruiter asked on one link and what the assistant answered (kept 14 days) — to see which questions come up. */
final class GetRecruiterConversation implements Tool
{
    public function __construct(private Users $users, private RecruiterLinks $links)
    {
    }

    public function name(): string
    {
        return 'get_recruiter_conversation';
    }

    public function description(): string
    {
        return 'Reads the conversation on one recruiter link (id from list_recruiter_links): the recruiter\'s questions and the assistant\'s answers, oldest first. Kept for 14 days. '
            . 'Use for "what did the Acme recruiter ask", "show me the conversation on that link".';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'The link id.']], 'required' => ['id']];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Recruiter links are only available to the owner.'];
        }
        $link = $this->links->byId((int) ($arguments['id'] ?? 0));
        if ($link === null || (int) $link['user_id'] !== $userId) {
            return ['error' => 'No such recruiter link.'];
        }

        return ['link' => $link['label'], 'messages' => array_map(static fn (array $m): array => ['who' => $m['role'] === 'user' ? 'recruiter' : 'assistant', 'at' => $m['created_at'], 'text' => $m['content']], $this->links->messages((int) $link['id'], 100))];
    }
}
