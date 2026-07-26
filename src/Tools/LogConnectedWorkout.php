<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ExerciseAliases;
use App\Data\Users;
use App\Data\Workouts;

/**
 * Tool: log workout sets ON BEHALF OF a connected person (e.g. logging your gym
 * partner's sets for them). This is the one cross-user WRITE, gated by an explicit
 * `workouts_log` grant the other person must give (separate from the read scope).
 * Every set is stamped "logged by <you>" so the entry is attributable and the owner
 * can see and undo what someone else added.
 */
final class LogConnectedWorkout implements Tool
{
    public function __construct(
        private Connections $connections,
        private Workouts $workouts,
        private ExerciseAliases $aliases,
        private Users $users,
    ) {
    }

    public function name(): string
    {
        return 'log_connected_workout';
    }

    public function description(): string
    {
        return 'Logs completed workout sets FOR a person you are connected with (logging on their behalf, '
            . 'e.g. your gym partner\'s sets) — ONLY if they have granted you permission to log their '
            . 'workouts. Identify them by email or name. Same "sets" shape as log_workout (one entry per '
            . 'performed set; weight in kg, omit for bodyweight). Use for "log Alex\'s bench: 3 sets of '
            . '5 at 60 kg", Danish "log Alex bænkpres". Only use when the user clearly means to record '
            . 'the OTHER person\'s training, not their own. Each set is attributed to you automatically.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person'   => ['type' => 'string', 'description' => 'Email or name of the connected person.'],
                'exercise' => ['type' => 'string', 'description' => 'Name of the exercise, e.g. "Bench Press".'],
                'sets' => [
                    'type'        => 'array',
                    'description' => 'One object per set performed. Repeat identical objects for repeated '
                        . 'identical sets (5x5 = five entries).',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'weight' => ['type' => 'number', 'description' => 'Weight in kg. Omit for bodyweight.'],
                            'reps'   => ['type' => 'integer', 'description' => 'Repetitions performed in this set.'],
                            'notes'  => ['type' => 'string', 'description' => 'Optional per-set note.'],
                        ],
                    ],
                ],
                'logged_at' => [
                    'type'        => 'string',
                    'description' => 'When it was done, "YYYY-MM-DD HH:MM:SS" UTC. Defaults to now.',
                ],
            ],
            'required' => ['person', 'exercise', 'sets'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = ConnectionAccess::resolve(
            $this->connections,
            $userId,
            (string) ($arguments['person'] ?? ''),
            'workouts_log',
            'They haven\'t given you permission to log workouts for them.'
        );
        if (isset($access['error'])) {
            return $access;
        }
        $ownerId = (int) $access['owner_id'];

        $exercise = trim((string) ($arguments['exercise'] ?? ''));
        $rawSets  = $arguments['sets'] ?? [];
        $loggedAt = isset($arguments['logged_at']) && $arguments['logged_at'] !== ''
            ? (string) $arguments['logged_at']
            : null;

        if ($exercise === '' || !is_array($rawSets) || $rawSets === []) {
            return ['error' => 'Provide an exercise name and at least one set.'];
        }

        // Canonicalise to the OWNER's exercise naming (their aliases, not yours).
        $exercise = $this->aliases->resolve($ownerId, $exercise);

        // Attribution stamp so the owner can see who logged it (and undo it).
        $actor     = $this->users->findById($userId);
        $actorName = trim((string) ($actor['name'] ?? '')) ?: (string) ($actor['email'] ?? 'a connection');
        $stamp     = 'logged by ' . $actorName;

        $sets = [];
        foreach ($rawSets as $set) {
            $set  = (array) $set;
            $note = isset($set['notes']) && $set['notes'] !== '' ? (string) $set['notes'] : null;
            $sets[] = [
                'weight' => isset($set['weight']) && $set['weight'] !== '' ? (float) $set['weight'] : null,
                'reps'   => isset($set['reps']) && $set['reps'] !== '' ? (int) $set['reps'] : null,
                'notes'  => $note !== null ? ($note . ' · ' . $stamp) : $stamp,
            ];
        }

        $ids = $this->workouts->logSets($ownerId, $exercise, $sets, $loggedAt);

        // Audit the cross-user write (the controlled hole in per-user scoping).
        error_log(sprintf(
            'log_connected_workout: viewer=%d owner=%d exercise=%s sets=%d',
            $userId,
            $ownerId,
            $exercise,
            count($ids)
        ));

        return [
            'logged_sets' => count($ids),
            'exercise'    => $exercise,
            'person'      => $access['person'],
            'note'        => 'Logged for ' . ((string) ($access['person']['name'] ?? 'them'))
                . '. Confirm to the user that these sets were recorded for that person, not for themselves.',
        ];
    }
}
