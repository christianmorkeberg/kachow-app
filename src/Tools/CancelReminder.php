<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Reminders;

/**
 * Tool: cancel a pending reminder by id.
 */
final class CancelReminder implements Tool
{
    public function __construct(private Reminders $reminders)
    {
    }

    public function name(): string
    {
        return 'cancel_reminder';
    }

    public function description(): string
    {
        return 'Cancels an upcoming reminder. Use for "cancel that reminder", "delete my reminder to …", '
            . 'Danish "annuller påmindelsen". Give the reminder id (use list_reminders first if unsure).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The reminder id to cancel.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'A reminder id is required.'];
        }

        return $this->reminders->cancel($userId, $id)
            ? ['cancelled' => true, 'id' => $id]
            : ['error' => 'No pending reminder with id ' . $id . '.'];
    }
}
