<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Conversations;
use App\Data\PushSubscriptions;
use App\Data\ScheduledTasks;

/**
 * Tool: save a standing instruction to re-run on a schedule (daily/weekly/hourly),
 * with FULL tool access and no one watching live — see bin/scheduled-tasks-cron.php,
 * which feeds `instruction` back into AssistantLoop as if the user had just typed it.
 */
final class ScheduleTask implements Tool
{
    private const MAX_ACTIVE_PER_USER = 15;
    private const WEEKDAYS = [
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
        'friday' => 5, 'saturday' => 6, 'sunday' => 7,
        'mandag' => 1, 'tirsdag' => 2, 'onsdag' => 3, 'torsdag' => 4,
        'fredag' => 5, 'lørdag' => 6, 'søndag' => 7,
    ];

    public function __construct(
        private ScheduledTasks $tasks,
        private Conversations $conversations,
        private PushSubscriptions $subscriptions,
    ) {
    }

    public function name(): string
    {
        return 'schedule_task';
    }

    public function description(): string
    {
        return 'Saves a standing instruction that runs AGAIN AND AGAIN on a schedule (hourly/daily/weekly) — '
            . 'use for "once a day, check my email for invoices and notify me if you find anything", "every '
            . 'Monday, summarise my week", Danish "hver dag kl 8, tjek...". On each run, YOUR FUTURE SELF '
            . 'receives `instruction` as a fresh message with FULL tool access (get_emails, read_email, '
            . 'everything) but NOBODY IS WATCHING LIVE — so write `instruction` narrowly: say exactly what '
            . 'to check and what is worth telling the user about, and call notify_me only when there is '
            . 'actually something to report (stay quiet otherwise). Do NOT instruct a future run to take an '
            . 'irreversible action (sending, deleting) unless the user explicitly asked for that. This is '
            . 'for a RECURRING check — use set_reminder instead for a one-off "remind me at X".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'instruction' => [
                    'type'        => 'string',
                    'description' => 'What the assistant should do on each run, written as an instruction to '
                        . 'itself (e.g. "Check my email for new invoice-like messages from the last day and '
                        . 'notify me if you find any."). This exact text is replayed as the user message on '
                        . 'every run.',
                ],
                'frequency' => [
                    'type' => 'string', 'enum' => ScheduledTasks::FREQUENCIES,
                    'description' => 'How often to run.',
                ],
                'time_of_day' => [
                    'type'        => 'string',
                    'description' => 'Local time of day "HH:MM" (24h) for daily/weekly. Defaults to "08:00" '
                        . 'if omitted. Ignored for hourly.',
                ],
                'day_of_week' => [
                    'type'        => 'string',
                    'description' => 'Required for weekly: the weekday name (e.g. "monday", Danish "mandag").',
                ],
                'label' => [
                    'type'        => 'string',
                    'description' => 'Optional short name for this task (shown in list_scheduled_tasks and as '
                        . 'its conversation title). Defaults to a short version of the instruction.',
                ],
            ],
            'required' => ['instruction', 'frequency'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $instruction = trim((string) ($arguments['instruction'] ?? ''));
        if ($instruction === '') {
            return ['error' => 'What should the recurring check actually do?'];
        }

        $frequency = trim((string) ($arguments['frequency'] ?? ''));
        if (!in_array($frequency, ScheduledTasks::FREQUENCIES, true)) {
            return ['error' => 'frequency must be one of: ' . implode(', ', ScheduledTasks::FREQUENCIES) . '.'];
        }

        $dayOfWeek = null;
        if ($frequency === 'weekly') {
            $dow = mb_strtolower(trim((string) ($arguments['day_of_week'] ?? '')));
            $dayOfWeek = self::WEEKDAYS[$dow] ?? null;
            if ($dayOfWeek === null) {
                return ['error' => 'A weekly task needs day_of_week (e.g. "monday").'];
            }
        }

        $timeOfDay = isset($arguments['time_of_day']) && trim((string) $arguments['time_of_day']) !== ''
            ? trim((string) $arguments['time_of_day'])
            : null;

        if ($this->tasks->countActive($userId) >= self::MAX_ACTIVE_PER_USER) {
            return ['error' => 'You already have ' . self::MAX_ACTIVE_PER_USER . ' scheduled tasks running — '
                . 'cancel one (cancel_scheduled_task) before adding another.'];
        }

        $label = trim((string) ($arguments['label'] ?? ''));
        if ($label === '') {
            $label = mb_substr($instruction, 0, 60) . (mb_strlen($instruction) > 60 ? '…' : '');
        }

        $conversationId = $this->conversations->start($userId);
        $this->conversations->setTitle($userId, $conversationId, 'Scheduled: ' . $label);

        $id = $this->tasks->create($userId, $conversationId, $label, $instruction, $frequency, $timeOfDay, $dayOfWeek);
        $next = ScheduledTasks::nextRunAtUtc($frequency, $timeOfDay, $dayOfWeek)
            ->setTimezone(new \DateTimeZone('Europe/Copenhagen'));

        return [
            'scheduled'        => true,
            'id'               => $id,
            'label'            => $label,
            'first_run_local'  => $next->format('D j M, H:i'),
            'has_device'       => $this->subscriptions->hasAny($userId),
            'note'             => $this->subscriptions->hasAny($userId)
                ? 'Confirm briefly — mention when it first runs and that it will only notify if it finds '
                    . 'something worth reporting.'
                : 'Saved and will run on schedule, but the user has NO notification device enabled — tell '
                    . 'them it can\'t actually notify them yet; turn on notifications (topbar menu) first.',
        ];
    }
}
