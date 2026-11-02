<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Reminders;

/**
 * Tool: answer a reminder that has fired — approve it (done), nudge it (remind again later) or restore one that was ticked by
 * mistake (reopen). The same actions the Reminders card has; the rules live in Data\Reminders.
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
        return 'Answers a reminder that has already fired. action "done" approves an OPEN one (the user did it — "I took the pills", '
            . '"done", "klaret"); action "nudge" pushes an open one again later (give "at" as a LOCAL "YYYY-MM-DD HH:MM", or '
            . '"minutes" from now; it stays open until approved); action "reopen" restores one the user ticked (approved) — "I ticked it '
            . 'by mistake", "put it back", "restore the vps reminder" (find it in list_reminders → recently_approved). Use list_reminders '
            . 'first if you do not know the id. ONLY act on the reminder the user clearly named or described: never nudge, approve or '
            . 'reopen another one as a guess — if you cannot tell which, ask.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'      => ['type' => 'integer', 'description' => 'The open reminder\'s id (from list_reminders).'],
                'action'  => ['type' => 'string', 'enum' => ['done', 'nudge', 'reopen'], 'description' => 'done = approve an open one; nudge = push it again later; reopen = restore one that was ticked.'],
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
        if ($id <= 0 || !in_array($action, ['done', 'nudge', 'reopen'], true)) {
            return ['error' => 'Give the reminder id and an action: done, nudge or reopen.'];
        }

        if ($action === 'reopen') {
            return $this->reminders->reopen($userId, $id)
                ? ['reopened' => true, 'id' => $id, 'still_open' => $this->reminders->openCount($userId)]
                : ['error' => 'No approved reminder with id ' . $id . ' to restore (it may be open already, or cancelled).'];
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
