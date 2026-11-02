<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Reminders;

/**
 * Tool: answer a reminder that has fired and is waiting for the user — approve it (done) or nudge it (remind again
 * later). The same two actions the Reminders card has; the rules live in Data\Reminders.
 */
final class ResolveReminder implements Tool
{
    public function __construct(private Reminders $reminders)
    {
    }

    public function name(): string
    {
        return 'resolve_reminder';
    }

    public function description(): string
    {
        return 'Answers a reminder that has already fired and is still open (waiting for the user). action "done" approves it '
            . '(the user did it — "I took the pills", "done", "klaret"); action "nudge" asks Kachow to push it again later '
            . '(give "at" as a LOCAL "YYYY-MM-DD HH:MM", or "minutes" from now). A nudged reminder stays open until approved. '
            . 'Only nudge when the user asks for it. Use list_reminders first if you do not know the id.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'      => ['type' => 'integer', 'description' => 'The open reminder\'s id (from list_reminders).'],
                'action'  => ['type' => 'string', 'enum' => ['done', 'nudge'], 'description' => 'done = approve it; nudge = push it again later.'],
                'at'      => ['type' => 'string', 'description' => 'For nudge: local wall-clock time "YYYY-MM-DD HH:MM" (24h), in the future. Do NOT convert to UTC.'],
                'minutes' => ['type' => 'integer', 'description' => 'For nudge: minutes from now, instead of "at".'],
            ],
            'required' => ['id', 'action'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id     = (int) ($arguments['id'] ?? 0);
        $action = (string) ($arguments['action'] ?? '');
        if ($id <= 0 || !in_array($action, ['done', 'nudge'], true)) {
            return ['error' => 'Give the reminder id and an action: done or nudge.'];
        }

        if ($action === 'done') {
            return $this->reminders->approve($userId, $id)
                ? ['approved' => true, 'id' => $id, 'still_open' => $this->reminders->openCount($userId)]
                : ['error' => 'No open reminder with id ' . $id . ' (it may already be approved).'];
        }

        $utc = Reminders::futureUtc((string) ($arguments['at'] ?? ''), (int) ($arguments['minutes'] ?? 0));
        if ($utc === null) {
            return ['error' => 'Give a future time to nudge: "at" as a local "YYYY-MM-DD HH:MM", or "minutes" from now.'];
        }

        return $this->reminders->nudge($userId, $id, $utc)
            ? ['nudged' => true, 'id' => $id, 'again_at' => Reminders::localLabel($utc), 'note' => 'It stays open until the user approves it.']
            : ['error' => 'No open reminder with id ' . $id . '.'];
    }
}
