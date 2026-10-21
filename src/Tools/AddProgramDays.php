<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\TrainingPrograms;
use App\Support\ProgramInput;

/**
 * Tool: put days into a training programme — each a week, a weekday and its exercises with sets / reps / weight / note. Sending a day that
 * already exists replaces it, so a correction is just sending that day again.
 */
final class AddProgramDays implements Tool
{
    public function __construct(private TrainingPrograms $programs)
    {
    }

    public function name(): string
    {
        return 'add_program_days';
    }

    public function description(): string
    {
        return 'Adds (or replaces) days in a training programme. `program` is its name or id (from save_training_program). Each day: `week` (the calendar week '
            . 'number, e.g. 43, or 1, 2, 3 for a relative programme), `weekday` (1=Monday … 7=Sunday, or "onsdag"/"wednesday"), optional `title` ("Pull + legs") and '
            . '`exercises`: each with `exercise` (the name as the user wrote it) and, where the note gives them, `sets`, `reps`, `weight` (kg) — numbers only; anything '
            . 'else ("8–10", "to failure", "or pull-ups", "heavy") goes in `note`. Keep the user\'s exact numbers; never fill in or average. Do one or two '
            . 'weeks per call (at most ' . TrainingPrograms::MAX_DAYS_PER_CALL . ' days). A day sent again replaces that day. For a calendar programme the year defaults to the '
            . 'current one (next year for a week well in the past); pass `year` only if the note says. When done, show the result with get_training_program.';
    }

    public function parameters(): array
    {
        $exercise = ['type' => 'object', 'properties' => [
            'exercise' => ['type' => 'string'], 'sets' => ['type' => 'integer'], 'reps' => ['type' => 'integer'],
            'weight' => ['type' => 'number', 'description' => 'kg'], 'note' => ['type' => 'string'],
        ], 'required' => ['exercise']];

        return [
            'type'       => 'object',
            'properties' => [
                'program' => ['type' => 'string', 'description' => 'Programme name or id.'],
                'days'    => ['type' => 'array', 'description' => 'The days to add.', 'items' => ['type' => 'object', 'properties' => [
                    'week'      => ['type' => 'integer', 'description' => 'Calendar week number (43) or week 1, 2, 3 …'],
                    'year'      => ['type' => 'integer', 'description' => 'Optional ISO year for a calendar week.'],
                    'weekday'   => ['type' => 'string', 'description' => '1–7 or a weekday name.'],
                    'title'     => ['type' => 'string'],
                    'note'      => ['type' => 'string'],
                    'exercises' => ['type' => 'array', 'items' => $exercise],
                ], 'required' => ['week', 'weekday', 'exercises']]],
            ],
            'required' => ['program', 'days'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $p = $this->programs->find($userId, (string) ($arguments['program'] ?? ''));
        if ($p === null) {
            return ['error' => 'No programme matches "' . ($arguments['program'] ?? '') . '" — save_training_program first (list_programs shows them via get_training_program).'];
        }
        $days = is_array($arguments['days'] ?? null) ? array_values($arguments['days']) : [];
        if ($days === []) {
            return ['error' => 'Give at least one day.'];
        }
        if (count($days) > TrainingPrograms::MAX_DAYS_PER_CALL) {
            return ['error' => 'At most ' . TrainingPrograms::MAX_DAYS_PER_CALL . ' days per call — send the rest in another call.'];
        }
        $saved = $replaced = $exercises = 0;
        $skipped = [];
        foreach ($days as $i => $d) {
            $week    = ProgramInput::week($d['week'] ?? null);
            $weekday = ProgramInput::weekday($d['weekday'] ?? null);
            $exs     = is_array($d['exercises'] ?? null) ? $d['exercises'] : [];
            if ($week === null || $weekday === null) {
                $skipped[] = 'day #' . ($i + 1) . ': week and weekday must be a week number and Monday–Sunday';
                continue;
            }
            if ($exs === []) {
                $skipped[] = 'week ' . $week . ' day ' . $weekday . ': no exercises (a rest day needs no entry)';
                continue;
            }
            $year = 0;
            if ($p['week_mode'] === 'calendar') {
                $year = isset($d['year']) && (int) $d['year'] >= 2000 ? (int) $d['year'] : ProgramInput::inferYear($week);
            }
            $r = $this->programs->upsertDay($userId, $p['id'], $week, $year, $weekday, isset($d['title']) ? (string) $d['title'] : null, $exs, isset($d['note']) ? (string) $d['note'] : null);
            if ($r === null) {
                $skipped[] = 'week ' . $week . ' day ' . $weekday . ': could not be saved';
                continue;
            }
            $saved++;
            $exercises += $r['items'];
            if ($r['replaced']) {
                $replaced++;
            }
        }
        $now = $this->programs->get($userId, $p['id']);

        return [
            'program' => $p['name'], 'days_saved' => $saved, 'days_replaced' => $replaced, 'exercises_saved' => $exercises,
            'skipped' => $skipped, 'programme_now' => ['days' => $now['days'] ?? 0, 'weeks' => ($now['first_week'] ?? null) !== null ? $now['first_week'] . '–' . $now['last_week'] : null],
            'note' => $skipped === [] ? 'Saved. Continue with the next week, or show it with get_training_program.' : 'Some days were skipped (see skipped) — fix those and send them again.',
        ];
    }
}
