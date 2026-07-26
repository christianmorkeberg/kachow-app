<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ExerciseAliases;
use App\Data\Workouts;

/**
 * Tool: chart a CONNECTED person's exercise progression — the same interactive
 * line-chart card as get_workout_progress, but for someone who shares their
 * workouts with the acting user. Reads go through the audited ConnectionAccess
 * gate (accepted connection + 'workouts' scope); the card's own tap controls
 * (api/workout-progress.php) re-check the gate on every request.
 */
final class GetConnectedWorkoutProgress implements Tool
{
    public function __construct(
        private Connections $connections,
        private Workouts $workouts,
        private ExerciseAliases $aliases,
    ) {
    }

    public function name(): string
    {
        return 'get_connected_workout_progress';
    }

    public function description(): string
    {
        return 'Charts a connected person\'s progression for a single exercise as an interactive '
            . 'line-chart card (same as get_workout_progress, but for someone who shares their workouts '
            . 'with you). Use for "show Alex\'s bench progression", "how has Alex trended on squat?", '
            . 'Danish "vis Alex fremgang i bænkpres". Identify them by email or name (see '
            . 'list_connections). NOTE they may name exercises in another language (e.g. Danish '
            . '"Bænkpres") — omit the exercise to chart their most recently trained one, or pass their '
            . 'exact name. Describe the peak (best) and the general trend; do NOT compute a '
            . 'latest-vs-first change or a percentage.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person'   => ['type' => 'string', 'description' => 'Email or name of the connected person.'],
                'exercise' => [
                    'type'        => 'string',
                    'description' => 'Exercise name (exact). Omit to use their most recently trained one.',
                ],
                'metric' => [
                    'type'        => 'string',
                    'enum'        => array_keys(GetWorkoutProgress::METRICS),
                    'description' => 'Which metric to plot. Default est_1rm.',
                ],
                'weeks' => [
                    'type'        => 'integer',
                    'description' => 'Look-back window in weeks (4, 12, 26 or 52). Default 12.',
                ],
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
        $metric   = isset($arguments['metric']) ? (string) $arguments['metric'] : GetWorkoutProgress::DEFAULT_METRIC;
        $weeks    = isset($arguments['weeks']) && $arguments['weeks'] !== ''
            ? (int) $arguments['weeks'] : GetWorkoutProgress::DEFAULT_WEEKS;

        $card = GetWorkoutProgress::buildCard(
            $this->workouts,
            (int) $access['owner_id'],
            $exercise,
            $metric,
            $weeks,
            $this->aliases,
        );
        // Tag the card as a connection's so the UI attributes it and its tap controls
        // post back the person (re-gated server-side, never trusting a client owner id).
        $card = self::tagPerson($card, $access['person']);

        if (empty($card['has_data'])) {
            return [
                'has_data' => false,
                'person'   => $access['person'],
                'message'  => ($card['exercise'] ?? null) === null
                    ? 'No workouts shared yet for that person.'
                    : 'No sets for "' . $card['exercise'] . '" in the last ' . $card['weeks'] . ' weeks.',
                '_render'  => $card,
            ];
        }

        return [
            'person'   => $access['person'],
            'exercise' => $card['exercise'],
            'metric'   => GetWorkoutProgress::METRICS[$card['metric']],
            'weeks'    => $card['weeks'],
            'unit'     => $card['unit'],
            'sessions' => $card['summary']['sessions'],
            'best'     => $card['summary']['best'],
            'latest'   => $card['summary']['last'],
            'note'     => 'This is the connected person\'s data — attribute it to THEM, never mixing with '
                . 'your own. Do NOT frame the latest-vs-first difference as progress or give a percentage; '
                . 'talk about the peak (best) and the overall trend. For est_1rm, 1-rep points are tested '
                . 'maxes (diamonds); multi-rep points are Epley estimates.',
            '_render'  => $card,
        ];
    }

    /**
     * Stamps the connection identity onto a progression card: a display `person`
     * and a `person_ref` (email preferred — unique) the interactive endpoint uses
     * to re-resolve the owner through the gate.
     *
     * @param array<string, mixed> $card
     * @param array<string, mixed> $person
     * @return array<string, mixed>
     */
    public static function tagPerson(array $card, array $person): array
    {
        $ref = (string) ($person['email'] ?? '');
        if ($ref === '') {
            $ref = (string) ($person['name'] ?? '');
        }
        $card['person'] = [
            'name'  => (string) ($person['name'] ?? ''),
            'email' => (string) ($person['email'] ?? ''),
        ];
        $card['person_ref'] = $ref;

        return $card;
    }
}
