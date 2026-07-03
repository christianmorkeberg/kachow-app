<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\Workouts;

/**
 * Tool: read a connected person's workout history — only if they share workouts
 * with the acting user. The permission check (accepted connection + 'workouts'
 * scope) is the controlled hole in the otherwise strict per-user scoping.
 */
final class GetConnectedWorkouts implements Tool
{
    public function __construct(
        private Connections $connections,
        private Workouts $workouts,
    ) {
    }

    public function name(): string
    {
        return 'get_connected_workouts';
    }

    public function description(): string
    {
        return 'Gets the workout history of a person you are connected with, but only if they share '
            . 'their workouts with you. Identify them by email or name (see list_connections). Supports '
            . 'the same filters as your own history (exercise, date range, limit). Use for questions '
            . 'like "how much did Alex squat last week?".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person'   => ['type' => 'string', 'description' => 'Email or name of the connected person.'],
                'exercise' => ['type' => 'string', 'description' => 'Filter to one exercise (exact name).'],
                'from'     => ['type' => 'string', 'description' => 'Start of range, "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" UTC.'],
                'to'       => ['type' => 'string', 'description' => 'End of range, "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" UTC.'],
                'limit'    => ['type' => 'integer', 'description' => 'Maximum number of sets.'],
            ],
            'required' => ['person'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = ConnectionAccess::resolve($this->connections, $userId, (string) ($arguments['person'] ?? ''), 'workouts');
        if (isset($access['error'])) {
            return $access;
        }

        $exercise = isset($arguments['exercise']) && $arguments['exercise'] !== '' ? (string) $arguments['exercise'] : null;
        $from     = isset($arguments['from']) && $arguments['from'] !== '' ? (string) $arguments['from'] : null;
        $to       = isset($arguments['to']) && $arguments['to'] !== '' ? (string) $arguments['to'] : null;
        $limit    = isset($arguments['limit']) && $arguments['limit'] !== '' ? (int) $arguments['limit'] : null;

        $sets = $this->workouts->getHistory($access['owner_id'], $exercise, $from, $to, $limit);

        return ['person' => $access['person'], 'count' => count($sets), 'sets' => $sets];
    }
}
