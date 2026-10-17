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
        return 'Saves an instruction that the assistant runs ITSELF later, on a schedule: every N minutes, hourly, '
            . 'daily, on weekdays, weekly, or once at a set time. Use for "once a day, check my email for '
            . 'invoices and notify me", "every weekday at 07:00 turn on the kitchen lights", "at 13:00 check if '
            . 'I\'m home and if so turn on the living room lights", "tomorrow at 7 turn on the heating", '
            . 'Danish "hver dag kl 8, tjek...", "hver hverdag kl 7...". On each run, YOUR FUTURE SELF receives '
            . '`instruction` as a fresh message with FULL tool access (email, calendar, location, home '
            . 'devices, everything) but NOBODY IS WATCHING LIVE — so write `instruction` self-contained: '
            . 'exactly what to check, what to do when the condition holds, and what to do (usually nothing) '
            . 'when it does not. WEBSITE WATCH ("tell me when this is back in stock", "watch this page for a new job '
            . 'post", "overvåg siden", usually hourly or daily): put the full URL in the instruction and write it like '
            . '"Open <url> with fetch_webpage and look at since_last_check; call notify_me only if <what the user '
            . 'described> has happened — ignore other changes." (The first run only records the page.) Set notify_always=true when the run ACTS (turns something on, changes '
            . 'something) so the user is told what happened; leave it off for a pure check that should only '
            . 'speak up when it finds something. Do NOT instruct a future run to take an irreversible action '
            . '(sending, deleting) unless the user explicitly asked for that. A one-off "remind me at X" with '
            . 'no action behind it is set_reminder; this tool is for something the assistant must DO or CHECK.';
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
                    'description' => 'How often to run: "minutes" (every interval_minutes), "hourly", "daily", '
                        . '"weekdays" (Monday to Friday), "weekly" (needs day_of_week), or "once" (needs run_at).',
                ],
                'interval_minutes' => [
                    'type'        => 'integer',
                    'description' => 'For frequency "minutes": run every this many minutes (5 to 1440).',
                ],
                'run_at' => [
                    'type'        => 'string',
                    'description' => 'For frequency "once": local date and time "YYYY-MM-DD HH:MM" (24h), in '
                        . 'the future. Work out the date from today\'s date.',
                ],
                'notify_always' => [
                    'type'        => 'boolean',
                    'description' => 'true = each run tells the user what it did (use when the task acts on '
                        . 'something, e.g. turns lights on). false/omitted = only speak up when a check finds '
                        . 'something.',
                ],
                'time_of_day' => [
                    'type'        => 'string',
                    'description' => 'Local time of day "HH:MM" (24h) for daily/weekdays/weekly. Defaults to '
                        . '"08:00" if omitted. Ignored for minutes, hourly and once.',
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

        $interval = null;
        if ($frequency === 'minutes') {
            $interval = (int) ($arguments['interval_minutes'] ?? 0);
            if ($interval < ScheduledTasks::MIN_INTERVAL_MINUTES || $interval > ScheduledTasks::MAX_INTERVAL_MINUTES) {
                return ['error' => 'interval_minutes must be between ' . ScheduledTasks::MIN_INTERVAL_MINUTES
                    . ' and ' . ScheduledTasks::MAX_INTERVAL_MINUTES . ' (every run is a full assistant turn).'];
            }
        }

        $onceAt = null;
        if ($frequency === 'once') {
            $raw = trim((string) ($arguments['run_at'] ?? ''));
            try {
                $local = $raw === '' ? null : new \DateTimeImmutable($raw, new \DateTimeZone('Europe/Copenhagen'));
            } catch (\Throwable) {
                $local = null;
            }
            if ($local === null) {
                return ['error' => 'A one-off task needs run_at as "YYYY-MM-DD HH:MM" (local time).'];
            }
            $onceAt = $local->setTimezone(new \DateTimeZone('UTC'));
            if ($onceAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                return ['error' => 'That time has already passed — ask the user for a time in the future.'];
            }
        }

        $notifyAlways = filter_var($arguments['notify_always'] ?? false, FILTER_VALIDATE_BOOLEAN);

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

        $id = $this->tasks->create(
            $userId, $conversationId, $label, $instruction, $frequency, $timeOfDay, $dayOfWeek,
            $interval, $notifyAlways, $onceAt,
        );
        $next = ($onceAt ?? ScheduledTasks::nextRunAtUtc($frequency, $timeOfDay, $dayOfWeek, null, $interval))
            ->setTimezone(new \DateTimeZone('Europe/Copenhagen'));

        return [
            'scheduled'        => true,
            'id'               => $id,
            'label'            => $label,
            'first_run_local'  => $next->format('D j M, H:i'),
            'notify_always'    => $notifyAlways,
            'has_device'       => $this->subscriptions->hasAny($userId),
            'note'             => $this->subscriptions->hasAny($userId)
                ? 'Confirm briefly — mention when it first runs and ' . ($notifyAlways
                    ? 'that it will send a notification each time saying what it did.'
                    : 'that it will only notify if it finds something worth reporting.')
                : 'Saved and will run on schedule, but the user has NO notification device enabled — tell '
                    . 'them it can\'t actually notify them yet; turn on notifications (topbar menu) first.',
        ];
    }
}
