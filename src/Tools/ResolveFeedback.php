<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\FeedbackReports;
use App\Data\Users;

/**
 * Tool (admin/developer only): update the status of a feedback report — mark it seen or
 * resolved once the developer has dealt with it.
 */
final class ResolveFeedback implements Tool
{
    public function __construct(
        private Users $users,
        private FeedbackReports $reports,
    ) {
    }

    public function name(): string
    {
        return 'resolve_feedback';
    }

    public function description(): string
    {
        return 'Developer/admin only: mark a feedback report as resolved (or seen). Use after dealing '
            . 'with a report from list_feedback, e.g. "mark report 3 as done". Give the report id.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'     => ['type' => 'integer', 'description' => 'The feedback report id.'],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['seen', 'resolved', 'new'],
                    'description' => 'New status. Default "resolved".',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Feedback reports are only available to the developer/admin.'];
        }

        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'A report id is required.'];
        }
        $status = isset($arguments['status']) ? (string) $arguments['status'] : 'resolved';

        $ok = $this->reports->setStatus($id, $status);

        return $ok
            ? ['updated' => true, 'id' => $id, 'status' => $status]
            : ['error' => 'No report with id ' . $id . ' (or invalid status).'];
    }
}
