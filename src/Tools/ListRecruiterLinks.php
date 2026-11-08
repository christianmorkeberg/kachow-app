<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Users;
use App\Recruiter\RecruiterLinks;

/** Tool (owner/admin only): the recruiter links with their state, usage and opens, and the messages recruiters have left. */
final class ListRecruiterLinks implements Tool
{
    public function __construct(private Users $users, private RecruiterLinks $links, private ?\App\Recruiter\AboutEntries $entries = null)
    {
    }

    public function name(): string
    {
        return 'list_recruiter_links';
    }

    public function description(): string
    {
        return 'Lists the recruiter chat links (one per application): company/role, link, state (ok / expired / revoked / full), when it was opened, how many messages and tokens are used of the caps — '
            . 'and the messages recruiters have left for the owner (set include_messages). Use for "which recruiter links do I have", "has anyone opened the Acme link", "did a recruiter leave a message".';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'include_messages' => ['type' => 'boolean', 'description' => 'Also list the messages recruiters left (newest first), and mark them read.'],
        ]];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Recruiter links are only available to the owner.'];
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';
        $out  = [];
        foreach ($this->links->list($userId) as $l) {
            $out[] = [
                'id' => (int) $l['id'], 'label' => $l['label'], 'company' => $l['company'], 'role' => $l['role_title'], 'state' => RecruiterLinks::state($l),
                'url' => 'https://' . $host . '/meet/' . $l['token'], 'opened' => (int) $l['open_count'], 'first_opened_at' => $l['first_opened_at'], 'last_opened_at' => $l['last_opened_at'],
                'messages' => (int) $l['messages_used'] . '/' . (int) $l['message_cap'], 'tokens' => (int) $l['tokens_used'] . '/' . (int) $l['token_cap'], 'expires_at' => $l['expires_at'],
            ];
        }
        $res = ['count' => count($out), 'links' => $out];
        if ($this->entries !== null) {
            // The card carries the detail (links, messages, facts with their visibility); the model only needs the summary above.
            $res['_render'] = \App\Recruiter\RecruiterCard::build($userId, $this->links, $this->entries, $host);
        }
        if (!empty($arguments['include_messages'])) {
            $res['left_messages'] = $this->links->leads($userId);
            $this->links->markLeadsRead($userId);
        } else {
            $res['unread_messages'] = count($this->links->leads($userId, true));
        }

        return $res;
    }
}
