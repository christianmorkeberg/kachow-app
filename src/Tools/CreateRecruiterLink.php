<?php

declare(strict_types=1);

namespace App\Tools;

use App\Assistant\GeminiClient;
use App\Data\Users;
use App\Recruiter\AboutEntries;
use App\Recruiter\MatchSummary;
use App\Recruiter\RecruiterLinks;

/**
 * Tool (owner/admin only): makes a link to the recruiter chat for ONE application — optionally with the job posting, so the assistant can speak to the role.
 * Returns the link to paste into the application and the owner's match notes (strong matches, gaps) for review.
 */
final class CreateRecruiterLink implements Tool
{
    /** @param callable():GeminiClient $gemini built only when a posting needs its match notes */
    public function __construct(private Users $users, private RecruiterLinks $links, private AboutEntries $entries, private $gemini)
    {
    }

    public function name(): string
    {
        return 'create_recruiter_link';
    }

    public function description(): string
    {
        return 'Creates a link to the recruiter chat ("Kachow, your assistant") for one job application. Give a label (e.g. "Acme – energy data engineer"), optionally the company, '
            . 'the role and the JOB POSTING text (paste it in — the assistant then speaks to that role and is honest about gaps). Returns the link to put in the application and short '
            . 'match notes (strong fits, gaps) for you to review. Optional: expires_in_days, message_cap (messages per link, default 30), token_cap (spend ceiling). '
            . 'Use for "make a recruiter link for …", "new link for the Acme application".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'label'           => ['type' => 'string', 'description' => 'A name for the link, e.g. "Acme – energy data engineer".'],
                'company'         => ['type' => 'string', 'description' => 'The company.'],
                'role'            => ['type' => 'string', 'description' => 'The job title.'],
                'job_posting'     => ['type' => 'string', 'description' => 'The full text of the job posting, if there is one.'],
                'expires_in_days' => ['type' => 'integer', 'description' => 'The link stops working after this many days (default: never).'],
                'message_cap'     => ['type' => 'integer', 'description' => 'How many messages a recruiter may send on this link (default 30).'],
                'token_cap'       => ['type' => 'integer', 'description' => 'A spend ceiling in model tokens for this link (default 200000).'],
            ],
            'required'   => ['label'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Recruiter links are only available to the owner.'];
        }
        $label = trim((string) ($arguments['label'] ?? ''));
        if ($label === '') {
            return ['error' => 'Give the link a label, e.g. "Acme – energy data engineer".'];
        }
        $posting = isset($arguments['job_posting']) ? trim((string) $arguments['job_posting']) : '';
        $role    = isset($arguments['role']) ? trim((string) $arguments['role']) : '';
        $notes   = $posting !== '' ? MatchSummary::write(($this->gemini)(), $this->entries, $userId, $posting, $role) : null;
        $days    = isset($arguments['expires_in_days']) ? (int) $arguments['expires_in_days'] : 0;
        $link    = $this->links->create(
            $userId, $label, isset($arguments['company']) ? (string) $arguments['company'] : null, $role !== '' ? $role : null, $posting !== '' ? $posting : null, $notes,
            $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null,
            isset($arguments['message_cap']) ? (int) $arguments['message_cap'] : null, isset($arguments['token_cap']) ? (int) $arguments['token_cap'] : null
        );

        return [
            'created'        => true,
            'id'             => (int) $link['id'],
            'url'            => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk') . '/meet/' . $link['token'],
            'message_cap'    => (int) $link['message_cap'],
            'token_cap'      => (int) $link['token_cap'],
            'expires_at'     => $link['expires_at'],
            'match_notes'    => $notes,
            'note'           => 'Give the owner the url to paste into the application' . ($notes !== null ? ', and show them the match notes to review (they can change them with update_recruiter_link).' : '.')
                . ' The link works until revoked or expired; the owner gets a push when it is first opened.',
        ];
    }
}
