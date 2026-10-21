<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\TrainingPrograms;

/**
 * Tool: start a training programme — the container the days are added to. For a note the user wrote (any format) the assistant reads it
 * and builds the programme: this tool first, then add_program_days a week at a time.
 */
final class SaveTrainingProgram implements Tool
{
    public function __construct(private TrainingPrograms $programs)
    {
    }

    public function name(): string
    {
        return 'save_training_program';
    }

    public function description(): string
    {
        return 'Starts a TRAINING PROGRAMME (a multi-week workout plan the user follows week by week) and returns its id; then call add_program_days to fill it, '
            . 'a week or two per call. Use when the user gives you a programme — written however they like: a pasted note, "uge 43: mandag …", a list per week '
            . '— or asks to save one. week_mode "calendar" (default) when the note names calendar weeks ("uge 43", "week 43"); "relative" when it just says '
            . 'week 1, 2, 3 — then pass start_date (any date in week 1) and repeat_weeks if the block repeats (e.g. 4). Put the user\'s ORIGINAL TEXT in `source_note` '
            . 'so nothing is lost. An existing programme with the same name is returned, not duplicated (pass replace=true to empty it and start over). '
            . 'Afterwards show it with get_training_program so the user can check it.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name'         => ['type' => 'string', 'description' => 'A short name, e.g. "Autumn strength".'],
                'week_mode'    => ['type' => 'string', 'enum' => ['calendar', 'relative'], 'description' => 'calendar = ISO week numbers ("uge 43"); relative = week 1, 2, 3 from start_date.'],
                'start_date'   => ['type' => 'string', 'description' => 'Relative programmes: any date (YYYY-MM-DD) in week 1.'],
                'repeat_weeks' => ['type' => 'integer', 'description' => 'Relative programmes: repeats every N weeks (a 4-week block = 4).'],
                'source_note'  => ['type' => 'string', 'description' => 'The user\'s original note, verbatim.'],
                'replace'      => ['type' => 'boolean', 'description' => 'True to empty an existing programme of this name and rebuild it.'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'A programme needs a name.'];
        }
        $mode = ($arguments['week_mode'] ?? 'calendar') === 'relative' ? 'relative' : 'calendar';
        $start = isset($arguments['start_date']) ? trim((string) $arguments['start_date']) : null;
        if ($mode === 'relative' && ($start === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1)) {
            return ['error' => 'A relative programme (week 1, 2, 3 …) needs start_date: any date in week 1, as YYYY-MM-DD.'];
        }
        $existing = $this->programs->find($userId, $name);
        if ($existing !== null && mb_strtolower($existing['name']) === mb_strtolower($name)) {
            if (!filter_var($arguments['replace'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return ['exists' => true, 'id' => $existing['id'], 'name' => $existing['name'], 'days' => $existing['days'],
                    'note' => 'A programme with this name already exists — add_program_days adds to / updates it, or call again with replace=true to start over.'];
            }
            $this->programs->removeDays($userId, $existing['id'], null, null);
            $this->programs->update($userId, $existing['id'], ['week_mode' => $mode, 'start_date' => $start, 'repeat_weeks' => $arguments['repeat_weeks'] ?? null, 'status' => 'active']);

            return ['id' => $existing['id'], 'name' => $existing['name'], 'emptied' => true, 'note' => 'Emptied — now add the days with add_program_days.'];
        }
        if ($this->programs->countAll($userId) >= TrainingPrograms::MAX_PROGRAMS) {
            return ['error' => 'They already have ' . TrainingPrograms::MAX_PROGRAMS . ' programmes — delete or archive one first.'];
        }
        $id = $this->programs->create($userId, $name, $mode, $start, isset($arguments['repeat_weeks']) ? (int) $arguments['repeat_weeks'] : null,
            isset($arguments['source_note']) ? (string) $arguments['source_note'] : null);

        return ['id' => $id, 'name' => $name, 'week_mode' => $mode, 'note' => 'Created. Now call add_program_days with its days, one or two weeks per call.'];
    }
}
