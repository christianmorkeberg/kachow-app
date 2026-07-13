<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;
use App\Data\WorkLog;

/**
 * Tool: record what the user did at a job on a given day (free text), with
 * optional hours. The job (DTU/DSB) is taken from the user's "Arbejde" calendar
 * for that day when not stated; if that's ambiguous, the tool asks.
 */
final class LogWorkTime implements Tool
{
    public function __construct(private WorkLog $log, private Calendar $calendar)
    {
    }

    public function name(): string
    {
        return 'log_work_time';
    }

    public function description(): string
    {
        return 'Records what the user did at work on a day (free-text), for their work log. Use when '
            . 'they describe what they worked on — e.g. "at DTU today I prepped the lecture", or when '
            . 'answering the afternoon "what did you get done?" nudge. job is DTU/DSB etc.; if omitted it '
            . 'is inferred from that day\'s "Arbejde" calendar event. hours is OPTIONAL — do NOT ask for '
            . 'it; only include hours if the user states them. Defaults the day to today.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'description' => ['type' => 'string', 'description' => 'What they did, in their own words.'],
                'job'         => ['type' => 'string', 'description' => 'Which job (e.g. DTU, DSB). Omit to infer from the Arbejde calendar.'],
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
                    'message' => 'I couldn\'t find a job for that day in your Arbejde calendar — which job was it (e.g. DTU or DSB)?'];
            }
        }

        $this->log->add($userId, $date, $job, $hours, $description);

        [$from, $to, $label] = WorkLog::resolveRange('this_week', null, null);

        return [
            'logged'  => true,
            'job'     => $job,
            'date'    => $date,
            'hours'   => $hours,
            '_render' => $this->log->card($userId, $from, $to, 'Work log · this week'),
        ];
    }

    /** @return array<int, string> distinct jobs from the day's Arbejde events */
    private function jobsForDay(int $userId, string $date): array
    {
        try {
            $events = $this->calendar->eventsForDay($userId, $date, WorkLog::WORK_CALENDAR);
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
