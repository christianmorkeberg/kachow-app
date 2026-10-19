<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ScheduledTasks;
use App\Notify\NotificationTypes;
use App\Notify\Notifier;

/**
 * Tool: push a notification to the user's phone. Only meaningful during an UNATTENDED
 * scheduled-task run (see bin/scheduled-tasks-cron.php) — that's the only place
 * registering this tool, bound to that run's own task/conversation id so the
 * notification deep-links back to it. Not available in live chat: there, replying
 * already reaches the user, so there's nothing for this to add.
 */
final class NotifyMe implements Tool
{
    public function __construct(
        private Notifier $notifier,
        private ScheduledTasks $tasks,
        private int $taskId,
        private int $conversationId,
    ) {
    }

    public function name(): string
    {
        return 'notify_me';
    }

    public function description(): string
    {
        return 'Sends the user a phone push notification. Use when you have something worth telling them right '
            . 'now — a scheduled check actually found something, or this run\'s instructions say to report what '
            . 'it did. Otherwise stay quiet (don\'t call this); silence is the normal outcome of a check that '
            . 'found nothing. A phone push banner cannot hold a tappable link, so when the user needs to open a page '
            . '(a survey, a booking), put the FULL https URL in the body: tapping the notification opens the app '
            . 'with the text and the URL as a link they can tap.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Short notification title.'],
                'body'  => ['type' => 'string', 'description' => 'The notification text — what you found.'],
            ],
            'required' => ['title', 'body'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $title = trim((string) ($arguments['title'] ?? ''));
        $body  = trim((string) ($arguments['body'] ?? ''));
        if ($title === '' || $body === '') {
            return ['error' => 'Give both a title and body for the notification.'];
        }

        $sent = $this->notifier->notify(
            $userId,
            NotificationTypes::SCHEDULED_TASK,
            $title,
            $body,
            '/?conv=' . $this->conversationId,
        );
        // Marked regardless of $sent — a missed/undelivered push still needs to surface as
        // an in-app "something to see" banner (cleared when the conversation is opened).
        $this->tasks->markResult($this->taskId, $title);

        // title/body ride in the stored tool row, so opening the run from the push shows what it said.
        return ['notified' => $sent > 0, 'title' => $title, 'body' => $body];
    }
}
