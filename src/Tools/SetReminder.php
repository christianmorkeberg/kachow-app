<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\PushSubscriptions;
use App\Data\Reminders;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: set a one-off reminder that is delivered as a push notification at the given
 * time. The model resolves natural language ("tomorrow at 9", "in 2 hours") into a
 * concrete LOCAL wall-clock time; this tool stores it in UTC and a 5-minute cron
 * pushes it when due.
 */
final class SetReminder implements Tool
{
    private const LOCAL_TZ = 'Europe/Copenhagen';

    public function __construct(
        private Reminders $reminders,
        private PushSubscriptions $subscriptions,
    ) {
    }

    public function name(): string
    {
        return 'set_reminder';
    }

    public function description(): string
    {
        return 'Sets a one-off reminder delivered as a phone push notification at a specific time. Use '
            . 'when the user asks to be reminded of something ("remind me to call mum tomorrow at 18:00", '
            . '"in 2 hours, remind me to move the laundry", Danish "mind mig om at ringe i morgen kl 9"). '
            . 'Work out the exact LOCAL date+time from the current time given in the system prompt and pass '
            . 'it as "at" ("YYYY-MM-DD HH:MM", 24h) — do NOT convert to UTC. Reminders fire within ~5 '
            . 'minutes of the set time. This is a timed push, NOT a calendar event (use '
            . 'insert_calendar_event for an appointment).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'text' => [
                    'type'        => 'string',
                    'description' => 'What to remind the user about, phrased as the reminder they will see '
                        . '(e.g. "Call mum", "Move the laundry").',
                ],
                'at' => [
                    'type'        => 'string',
                    'description' => 'Local wall-clock time to fire, "YYYY-MM-DD HH:MM" (24h). Do NOT convert '
                        . 'to UTC. Must be in the future.',
                ],
            ],
            'required' => ['text', 'at'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $text = trim((string) ($arguments['text'] ?? ''));
        $at   = trim((string) ($arguments['at'] ?? ''));
        if ($text === '' || $at === '') {
            return ['error' => 'Give both what to remind about and when (a future local date/time).'];
        }

        $local = $this->parseLocal($at);
        if ($local === null) {
            return ['error' => 'I could not read that time. Give it like "2026-07-22 09:00".'];
        }

        $utc    = $local->setTimezone(new DateTimeZone('UTC'));
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($utc->getTimestamp() <= $nowUtc->getTimestamp()) {
            return ['error' => 'That time is already in the past — give a future time.'];
        }

        $id = $this->reminders->create($userId, $utc->format('Y-m-d H:i:s'), $text);

        return [
            'scheduled'  => true,
            'id'         => $id,
            'text'       => $text,
            'when_local' => $local->format('D j M, H:i'),
            'has_device' => $this->subscriptions->hasAny($userId),
            'note'       => $this->subscriptions->hasAny($userId)
                ? 'Confirm briefly. It fires within ~5 minutes of the time.'
                : 'The reminder is saved, but the user has NO notification device enabled — tell them to '
                    . 'turn on notifications (topbar menu) or they will not receive the push.',
        ];
    }

    /** Interprets a local wall-clock time, tolerating a stray "Z"/offset the model added. */
    private function parseLocal(string $at): ?DateTimeImmutable
    {
        $s = preg_replace('/\s*(Z|[+-]\d{2}:?\d{2})\s*$/i', '', trim($at)) ?? trim($at);
        try {
            return new DateTimeImmutable($s, new DateTimeZone(self::LOCAL_TZ));
        } catch (\Exception $e) {
            return null;
        }
    }
}
