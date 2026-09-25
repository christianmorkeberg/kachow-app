<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\ExerciseAliases;
use App\Data\Workouts;
use App\Support\OneRepMax;
use App\Support\TextMatch;
use App\Support\TrainingLoad as Calc;

/**
 * Tool: working weights computed in code from the user's real maxes — "85% of my bench",
 * "what can I do for 5 reps", "Alex does 70 kg, what's the same for me". Always answers
 * from BOTH the tested (realised 1-rep) max and the estimated 1RM, rounded to loadable
 * plates. A connected person's max is read only through ConnectionAccess (workouts scope).
 */
final class TrainingLoad implements Tool
{
    private const BASES = ['tested', 'estimated'];

    public function __construct(
        private Workouts $workouts,
        private ExerciseAliases $aliases,
        private Connections $connections,
    ) {
    }

    public function name(): string
    {
        return 'training_load';
    }

    public function description(): string
    {
        return 'Calculates working weights IN CODE from real logged maxes — use it for EVERY percentage / '
            . 'relative-load question instead of doing the maths yourself: "I need bench at 85%, how much is '
            . 'that", "what should I squat for 5 reps", "hvad er 80% af min dødløft", or relative to another '
            . 'person: "Alex does 70 kg, I want the same relative to my max" (pass person + person_weight). '
            . 'It looks up the max itself (tested = an actual 1-rep lift, estimated = Epley 1RM) and returns '
            . 'the load for BOTH, rounded to loadable plates (2.5 kg steps unless round_to says otherwise). '
            . 'Report both results with the max each came from; if one basis is missing, say so. If the user '
            . 'states their max ("my max is 100"), pass my_max. If the exercise isn\'t found, the result lists '
            . 'known_exercises — retry with the right name (the other person may name it differently, e.g. '
            . '"Bænkpres" vs "Bench press"; use person_exercise).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'exercise'        => ['type' => 'string', 'description' => 'The user\'s exercise, e.g. "Bench press".'],
                'percent'         => ['type' => 'number', 'description' => 'Percentage of max, e.g. 85.'],
                'reps'            => ['type' => 'integer', 'description' => 'Target reps — gives the load that max supports for that many reps (use when no percent is given).'],
                'person'          => ['type' => 'string', 'description' => 'Relative mode: the connected person (name/email) whose load to match.'],
                'person_weight'   => ['type' => 'number', 'description' => 'Relative mode: the load (kg) that person lifts.'],
                'person_exercise' => ['type' => 'string', 'description' => 'Relative mode: the exercise name as THEY log it, if different.'],
                'my_max'          => ['type' => 'number', 'description' => 'A max the user states, used instead of looking it up.'],
                'round_to'        => ['type' => 'number', 'description' => 'Loading step in kg (default 2.5; e.g. 1 or 2 for dumbbells).'],
                'since'           => ['type' => 'string', 'description' => 'Only count maxes logged on/after YYYY-MM-DD (e.g. to ignore an old peak).'],
            ],
            'required' => ['exercise'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $exerciseArg = trim((string) ($arguments['exercise'] ?? ''));
        if ($exerciseArg === '') {
            return ['error' => 'Which exercise?'];
        }
        $step  = isset($arguments['round_to']) && (float) $arguments['round_to'] > 0
            ? min(25.0, (float) $arguments['round_to']) : Calc::DEFAULT_STEP;
        $since = isset($arguments['since']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $arguments['since'])
            ? (string) $arguments['since'] : null;

        $percent  = isset($arguments['percent']) && $arguments['percent'] !== '' ? (float) $arguments['percent'] : null;
        $reps     = isset($arguments['reps']) && $arguments['reps'] !== '' ? (int) $arguments['reps'] : null;
        $person   = trim((string) ($arguments['person'] ?? ''));
        $theirKg  = isset($arguments['person_weight']) && $arguments['person_weight'] !== '' ? (float) $arguments['person_weight'] : null;
        $relative = $person !== '' || $theirKg !== null;

        if ($relative && ($person === '' || $theirKg === null || $theirKg <= 0)) {
            return ['error' => 'For a relative load give both person and person_weight (their load in kg).'];
        }
        if (!$relative && $percent === null && $reps === null) {
            return ['error' => 'Give a percent, target reps, or another person\'s load to match.'];
        }
        if ($percent !== null && ($percent <= 0 || $percent > 150)) {
            return ['error' => 'Percent must be between 0 and 150.'];
        }
        if ($reps !== null && ($reps < 1 || $reps > 30)) {
            return ['error' => 'Reps must be between 1 and 30.'];
        }

        // Your maxes: stated, or looked up from your records.
        if (isset($arguments['my_max']) && (float) $arguments['my_max'] > 0) {
            $mine = ['exercise' => $exerciseArg, 'stated' => (float) $arguments['my_max'], 'tested' => null, 'estimated' => null];
        } else {
            $mine = $this->maxes($userId, $exerciseArg, $since);
            if (isset($mine['error'])) {
                return $mine;
            }
        }
        $myBases = $this->bases($mine);

        $out = [
            'exercise'  => $mine['exercise'],
            'round_to'  => $step,
            'your_max'  => $this->describeMaxes($mine),
        ];

        if (!$relative) {
            $pct  = $percent ?? Calc::percentForReps((int) $reps);
            $mode = $percent !== null ? $pct . '% of max' : 'load for ' . $reps . ' reps (Epley)';
            $out['mode']    = $mode;
            $out['results'] = [];
            foreach ($myBases as $basis => $max) {
                $out['results'][$basis] = Calc::apply($max, $pct, $step);
            }
        } else {
            $access = ConnectionAccess::resolve($this->connections, $userId, $person, 'workouts');
            if (isset($access['error'])) {
                return $access;
            }
            $theirs = $this->maxes((int) $access['owner_id'], trim((string) ($arguments['person_exercise'] ?? '')) ?: $exerciseArg, $since);
            if (isset($theirs['error'])) {
                return $theirs + ['whose' => 'the other person\'s exercises'];
            }
            $theirBases = $this->bases($theirs);

            $out['mode']       = 'relative to ' . ($access['person']['name'] ?? 'them');
            $out['their_load'] = $theirKg;
            $out['their_max']  = $this->describeMaxes($theirs);
            $out['results']    = [];
            // Like for like: their tested vs your tested, their estimate vs your estimate. A
            // stated max of yours pairs with whichever of theirs exists.
            foreach (self::BASES as $basis) {
                $their = $theirBases[$basis] ?? null;
                $my    = $myBases[$basis] ?? ($myBases['stated'] ?? null);
                if ($their === null || $my === null) {
                    continue;
                }
                $pct = (float) Calc::relativePercent($theirKg, $their);
                $out['results'][$basis] = ['their_percent' => round($pct, 1)] + Calc::apply($my, $pct, $step);
            }
        }

        if ($out['results'] === []) {
            $out['note'] = 'No usable max for this — the user has not logged a tested or estimable max'
                . ($relative ? ' on both sides' : '') . '. Ask for their max (my_max) instead of guessing.';
        } else {
            $missing = array_diff(self::BASES, array_keys($out['results']));
            if ($missing !== [] && !isset($myBases['stated'])) {
                $out['note'] = 'No ' . implode('/', $missing) . ' max available — say so, and give the other.';
            }
        }

        return $out;
    }

    /**
     * Tested + estimated max for one person's exercise, resolving the name via their aliases,
     * then case-insensitive / near matches among what they actually log.
     *
     * @return array<string, mixed>
     */
    private function maxes(int $ownerId, string $exerciseArg, ?string $since): array
    {
        $name = $this->aliases->resolve($ownerId, $exerciseArg);
        $sets = $this->workouts->getHistory($ownerId, $name, $since);
        if ($sets === []) {
            $known = $this->workouts->distinctExercises($ownerId, 40);
            $hit   = null;
            foreach ($known as $k) {
                if (TextMatch::normalize($k) === TextMatch::normalize($exerciseArg)) {
                    $hit = $k;
                    break;
                }
            }
            if ($hit === null) {
                foreach ($known as $k) {
                    if (TextMatch::similar($k, $exerciseArg, 80.0)) {
                        $hit = $k;
                        break;
                    }
                }
            }
            if ($hit !== null) {
                $name = $hit;
                $sets = $this->workouts->getHistory($ownerId, $name, $since);
            }
            if ($sets === []) {
                return [
                    'error'           => 'No logged sets for "' . $exerciseArg . '"' . ($since ? ' since ' . $since : '') . '.',
                    'known_exercises' => array_slice($known, 0, 20),
                ];
            }
        }

        $rec = OneRepMax::records($sets)[0] ?? [];

        return [
            'exercise'  => $name,
            'tested'    => $rec['tested_1rm'] ?? null,
            'estimated' => $rec['est_1rm'] ?? null,
        ];
    }

    /** @return array<string, float> basis => max kg */
    private function bases(array $m): array
    {
        $b = [];
        if (!empty($m['stated'])) {
            $b['stated'] = (float) $m['stated'];
        }
        if (!empty($m['tested']['weight'])) {
            $b['tested'] = (float) $m['tested']['weight'];
        }
        if (!empty($m['estimated']['value'])) {
            $b['estimated'] = (float) $m['estimated']['value'];
        }

        return $b;
    }

    /** @return array<string, mixed> */
    private function describeMaxes(array $m): array
    {
        $d = [];
        if (!empty($m['stated'])) {
            $d['stated'] = ['kg' => (float) $m['stated']];
        }
        $d['tested'] = !empty($m['tested'])
            ? ['kg' => (float) $m['tested']['weight'], 'date' => $m['tested']['date']]
            : null;
        $d['estimated'] = !empty($m['estimated'])
            ? ['kg' => (float) $m['estimated']['value'], 'date' => $m['estimated']['date'],
                'from' => $m['estimated']['from_weight'] . ' kg × ' . $m['estimated']['from_reps']]
            : null;

        return $d;
    }
}
