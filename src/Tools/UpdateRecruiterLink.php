<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Users;
use App\Recruiter\RecruiterLinks;

/** Tool (owner/admin only): changes or revokes a recruiter link — caps, expiry, label, the job posting or match notes, revoke / reactivate. */
final class UpdateRecruiterLink implements Tool
{
    public function __construct(private Users $users, private RecruiterLinks $links)
    {
    }

    public function name(): string
    {
        return 'update_recruiter_link';
    }

    public function description(): string
    {
        return 'Changes a recruiter link (by id from list_recruiter_links): revoke it (action "revoke"), reactivate it ("reactivate"), raise or lower message_cap / token_cap, set or clear the expiry '
            . '(expires_in_days, 0 = never), rename it, or replace its match_notes / job_posting. Use for "revoke the Acme link", "give the Acme link 60 messages".';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'id'              => ['type' => 'integer', 'description' => 'The link id.'],
            'action'          => ['type' => 'string', 'description' => '"revoke" or "reactivate" (omit to just change the other fields).'],
            'message_cap'     => ['type' => 'integer', 'description' => 'New message cap.'],
            'token_cap'       => ['type' => 'integer', 'description' => 'New spend ceiling in tokens.'],
            'expires_in_days' => ['type' => 'integer', 'description' => 'Expire this many days from now; 0 removes the expiry.'],
            'label'           => ['type' => 'string', 'description' => 'New label.'],
            'match_notes'     => ['type' => 'string', 'description' => 'Replacement match notes (what the assistant should lead with, and the gaps).'],
            'job_posting'     => ['type' => 'string', 'description' => 'Replacement job posting text.'],
        ], 'required' => ['id']];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Recruiter links are only available to the owner.'];
        }
        $id   = (int) ($arguments['id'] ?? 0);
        $link = $this->links->byId($id);
        if ($link === null || (int) $link['user_id'] !== $userId) {
            return ['error' => 'No such recruiter link.'];
        }
        $action = strtolower(trim((string) ($arguments['action'] ?? '')));
        if ($action === 'revoke') {
            $this->links->revoke($userId, $id);
        }
        $fields = [];
        foreach (['message_cap', 'token_cap', 'label', 'job_posting'] as $k) {
            if (isset($arguments[$k])) {
                $fields[$k] = $arguments[$k];
            }
        }
        if (isset($arguments['match_notes'])) {
            $fields['match_summary'] = (string) $arguments['match_notes'];
        }
        if (isset($arguments['expires_in_days'])) {
            $d = (int) $arguments['expires_in_days'];
            $fields['expires_at'] = $d > 0 ? gmdate('Y-m-d H:i:s', time() + $d * 86400) : null;
        }
        if ($action === 'reactivate') {
            $fields['reactivate'] = true;
        }
        if ($fields !== []) {
            $this->links->update($userId, $id, $fields);
        }
        $now = $this->links->byId($id) ?? $link;

        return ['updated' => true, 'id' => $id, 'state' => RecruiterLinks::state($now), 'message_cap' => (int) $now['message_cap'], 'token_cap' => (int) $now['token_cap'], 'expires_at' => $now['expires_at']];
    }
}
