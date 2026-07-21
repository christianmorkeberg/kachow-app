<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;
use App\Data\UserSettings;
use App\Data\WorkLog;

/**
 * Tool: record what the user did at a job on a given day (free text), with
 * optional hours. The job is taken from the user's work calendar (first word of
 * that day's event) when not stated; if that's ambiguous, the tool asks.
 */
final class LogWorkTime implements Tool
{
    public function __construct(
        private WorkLog $log,
        private Calendar $calendar,
        private UserSettings $settings,
    ) {
    }

    public function name(): string
    {
        return 'log_work_time';
    }

    public function description(): string
    {
        return 'Records what the user did at work on a day (free-text), for their work log. Use when '
            . 'they describe what they worked on — e.g. "at work today I prepped the lecture", or when '
            . 'answering the afternoon "what did you get done?" nudge. job is the workplace name; if '
            . 'omitted it is inferred from that day\'s work-calendar event. hours is OPTIONAL — do NOT ask '
            . 'for it; only include hours if the user states them. Defaults the day to today.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'description' => ['type' => 'string', 'description' => 'What they did, in their own words.'],
                'job'         => ['type' => 'string', 'description' => 'Which job (the workplace name). Omit to infer from the work calendar.'],
                'hours'       => ['type' => 'number', 'description' => 'Hours spent (optional).'],
                'date'        => ['type' => 'string', 'description' => 'Local date YYYY-MM-DD. Defaults to today.'],
            ],
            'required' => ['description'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $description = trim((string) ($arguments['description'] ?? ''));
        if ($description === '') {
            return ['error' => 'Tell me what you did and I\'ll log it.'];
        }
        $date  = trim((string) ($arguments['date'] ?? '')) ?: WorkLog::today();
        $hours = isset($arguments['hours']) && $arguments['hours'] !== '' && $arguments['hours'] !== null
            ? (float) $arguments['hours'] : null;

        $job = trim((string) ($arguments['job'] ?? ''));
        if ($job === '') {
            // Infer from the "Arbejde" calendar for that day.
            $jobs = $this->jobsForDay($userId, $date);
            if (count($jobs) === 1) {
                $job = $jobs[0];
            } elseif (count($jobs) > 1) {
                return ['need_job' => true, 'jobs' => $jobs,
                    'message' => 'You have more than one job on that day (' . implode(', ', $jobs)
                        . '). Which one is this for?'];
            } else {
                return ['need_job' => true,
                    'message' => 'I couldn\'t find a job for that day in your work calendar — which job was it?'];
            }
        }

        $this->log->add($userId, $date, $job, $hours, $description);

        [$from, $to, $label] = WorkLog::resolveRange('this_week', null, null);

        // Recent entries for THIS job (the ~4 weeks before today) so the assistant can
        // ask a follow-up that builds on prior threads instead of starting cold.
        $histFrom = date('Y-m-d', strtotime($date . ' -28 days'));
        $histTo   = date('Y-m-d', strtotime($date . ' -1 day'));
        $recent   = array_slice($this->log->listForUser($userId, $histFrom, $histTo, $job), 0, 6);
        $recentEntries = array_map(static fn (array $r): array => [
            'date'        => $r['date'],
            'description' => mb_substr($r['description'], 0, 200),
        ], $recent);

        return [
            'logged'         => true,
            'job'            => $job,
            'date'           => $date,
            'hours'          => $hours,
            'recent_entries' => $recentEntries,
            'followup_hint'  => 'You MAY ask ONE short, natural follow-up about the work itself, grounded '
                . 'in recent_entries when something connects (e.g. whether an ongoing task from a previous '
                . 'day got finished). Keep it to a single light question, skip it if nothing stands out, '
                . 'and never ask about hours.',
            '_render'        => $this->log->card($userId, $from, $to, 'Work log · this week'),
        ];
    }

    /** @return array<int, string> distinct jobs from the day's Arbejde events */
    private function jobsForDay(int $userId, string $date): array
    {
        $calendarName = $this->settings->get($userId, 'work_calendar') ?? WorkLog::WORK_CALENDAR;
        try {
            $events = $this->calendar->eventsForDay($userId, $date, $calendarName);
        } catch (\Throwable) {
            return [];
        }
        $jobs = [];
        foreach ($events as $e) {
            $job = WorkLog::jobFromTitle((string) ($e['summary'] ?? ''));
            if ($job !== '' && !in_array($job, $jobs, true)) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }
}
