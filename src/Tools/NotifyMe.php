<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ScheduledTasks;
use App\Data\TaskOffers;
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
        private ?TaskOffers $offers = null,
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
            . 'with the text and the URL as a link they can tap. '
            . 'To ASK the user whether to do something ("the lights are on — turn them off?"), do NOT only write the question: pass `offer` with the '
            . 'exact tool calls to make on a yes (e.g. the home_device_call PUTs that switch each light off, using the ids you just read). The notification '
            . 'then opens a Run / Skip sheet and Run makes those calls — nothing else can act on a "yes" to a notification. Offers may only use '
            . implode(', ', TaskOffers::TOOLS) . '.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Short notification title.'],
                'body'  => ['type' => 'string', 'description' => 'The notification text — what you found. For an offer, the question ("11 lights are on. Turn them off?").'],
                'offer' => ['type' => 'object', 'description' => 'Optional: what Run does. {"calls":[{"tool":"home_device_call","args":{...}}, ...]} — the exact calls, at most 40.',
                    'properties' => ['calls' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['tool' => ['type' => 'string', 'enum' => TaskOffers::TOOLS], 'args' => ['type' => 'object']], 'required' => ['tool']]]]],
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

        $url     = '/?conv=' . $this->conversationId;
        $offerId = null;
        $note    = null;
        if (isset($arguments['offer'])) {
            $clean = TaskOffers::clean(is_array($arguments['offer']) ? ($arguments['offer']['calls'] ?? null) : null);
            if (isset($clean['error'])) {
                return ['error' => $clean['error']];   // nothing sent: the model fixes the offer and calls again
            }
            try {
                $offerId = ($this->offers ?? new TaskOffers())->create($userId, $this->conversationId, $this->taskId, $body, $clean['calls']);
                $url     = '/?offer=' . $offerId;
            } catch (\Throwable $e) {
                error_log('notify_me offer: ' . $e->getMessage());   // the table is not migrated yet: the notification still goes out, without a Run button
                $note = 'The Run button is not available right now (offers are not set up on the server); the notification was sent as a plain message.';
            }
        }

        $sent = $this->notifier->notify(
            $userId,
            NotificationTypes::SCHEDULED_TASK,
            $title,
            $body,
            $url,
        );
        // Marked regardless of $sent — a missed/undelivered push still needs to surface as
        // an in-app "something to see" banner (cleared when the conversation is opened).
        $this->tasks->markResult($this->taskId, $title);

        // title/body ride in the stored tool row, so opening the run from the push shows what it said.
        $out = ['notified' => $sent > 0, 'title' => $title, 'body' => $body];
        if ($offerId !== null) {
            $out['offer_id'] = $offerId;
            $out['note']     = 'The notification carries a Run / Skip sheet: the user taps Run and those calls are made. Do not do them yourself now.';
        } elseif ($note !== null) {
            $out['note'] = $note;
        }

        return $out;
    }
}
