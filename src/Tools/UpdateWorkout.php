<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Workouts;

/**
 * Tool: correct a previously logged workout set. Thin wrapper over
 * Data\Workouts::update.
 */
final class UpdateWorkout implements Tool
{
    public function __construct(private Workouts $workouts)
    {
    }

    public function name(): string
    {
        return 'update_workout';
    }

    public function description(): string
    {
        return 'Corrects a previously logged workout SET (one row = one set). First call '
            . 'get_workout_history to find the set and its id, then pass the id and only the fields to '
            . 'change. Use to fix a mistake, e.g. a wrong weight or rep count on one set.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'        => ['type' => 'integer', 'description' => 'Id of the set to update (from get_workout_history).'],
                'exercise'  => ['type' => 'string', 'description' => 'New exercise name.'],
                'weight'    => ['type' => 'number', 'description' => 'New weight in kg.'],
                'reps'      => ['type' => 'integer', 'description' => 'New rep count.'],
                'notes'     => ['type' => 'string', 'description' => 'New note for the set.'],
                'logged_at' => ['type' => 'string', 'description' => 'New timestamp, "YYYY-MM-DD HH:MM:SS" UTC.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = isset($arguments['id']) ? (int) $arguments['id'] : 0;
        if ($id <= 0) {
            return ['error' => 'A valid set id is required (from get_workout_history).'];
        }

        $fields = [];
        if (isset($arguments['exercise']) && $arguments['exercise'] !== '') {
            $fields['exercise'] = (string) $arguments['exercise'];
        }
        if (isset($arguments['weight']) && $arguments['weight'] !== '') {
            $fields['weight'] = (float) $arguments['weight'];
        }
        if (isset($arguments['reps']) && $arguments['reps'] !== '') {
            $fields['reps'] = (int) $arguments['reps'];
        }
        if (array_key_exists('notes', $arguments)) {
            $fields['notes'] = $arguments['notes'] !== '' ? (string) $arguments['notes'] : null;
        }
        if (isset($arguments['logged_at']) && $arguments['logged_at'] !== '') {
            $fields['logged_at'] = (string) $arguments['logged_at'];
        }

        if ($fields === []) {
            return ['error' => 'Specify at least one field to change (weight, reps, notes, exercise, logged_at).'];
        }

        $ok = $this->workouts->update($userId, $id, $fields);

        return $ok ? ['updated' => true, 'id' => $id] : ['updated' => false, 'error' => 'No matching set found.'];
    }
}
