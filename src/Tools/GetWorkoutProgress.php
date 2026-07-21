<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ExerciseAliases;
use App\Data\Workouts;

/**
 * Tool: chart an exercise's progression over time. Aggregates the user's logged sets
 * into one point per training day for a chosen metric (estimated 1RM, top-set weight,
 * or total volume) and returns an interactive line-chart card. Use when the user asks
 * whether they're getting stronger / making progress on a lift, or wants to see a trend
 * ("show my bench progression", "am I improving on squats", Danish "hvordan går det med
 * mit bænkpres", "bliver jeg stærkere i squat").
 *
 * The heavy lifting lives in the static buildCard() so the card's own interactive
 * controls (api/workout-progress.php) rebuild it with the exact same shape.
 */
final class GetWorkoutProgress implements Tool
{
    /** metric key => human label (also the source of truth for validation). */
    public const METRICS = [
        'est_1rm'    => 'Estimated 1RM',
        'top_weight' => 'Top set',
        'volume'     => 'Volume',
    ];

    /** Selectable look-back windows, in weeks. */
    public const RANGES = [4, 12, 26, 52];

    public const DEFAULT_METRIC = 'est_1rm';
    public const DEFAULT_WEEKS  = 12;

    public function __construct(
        private Workouts $workouts,
        private ExerciseAliases $aliases,
    ) {
    }

    public function name(): string
    {
        return 'get_workout_progress';
    }

    public function description(): string
    {
        return 'Charts progression for a single exercise over time as an interactive line-chart card: '
            . 'one point per training day for a metric (est_1rm = estimated one-rep max, top_weight = '
            . 'heaviest set, volume = weight×reps summed). Use for "am I getting stronger on bench?", '
            . '"show my squat progress", "how has my deadlift trended?", Danish "bliver jeg stærkere i '
            . 'bænkpres?", "vis min fremgang i squat". Omit exercise to chart the most recently trained '
            . 'one. The card lets the user switch exercise, metric and time range. Describe the peak '
            . '(best) and the general trend; do NOT compute a latest-vs-first change or a percentage '
            . '(sessions vary in intensity, so a lighter recent session is not a regression).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'exercise' => [
                    'type'        => 'string',
                    'description' => 'Exercise name (exact). Omit to use the most recently trained exercise.',
                ],
                'metric' => [
                    'type'        => 'string',
                    'enum'        => array_keys(self::METRICS),
                    'description' => 'Which metric to plot. Default est_1rm.',
                ],
                'weeks' => [
                    'type'        => 'integer',
                    'description' => 'Look-back window in weeks (4, 12, 26 or 52). Default 12.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $exercise = isset($arguments['exercise']) && $arguments['exercise'] !== ''
            ? (string) $arguments['exercise'] : null;
        $metric = isset($arguments['metric']) ? (string) $arguments['metric'] : self::DEFAULT_METRIC;
        $weeks  = isset($arguments['weeks']) && $arguments['weeks'] !== ''
            ? (int) $arguments['weeks'] : self::DEFAULT_WEEKS;

        $card = self::buildCard($this->workouts, $userId, $exercise, $metric, $weeks, $this->aliases);

        if (!$card['has_data']) {
            return [
                'has_data' => false,
                'message'  => $card['exercise'] === null
                    ? 'No workouts logged yet — log some sets first to chart progression.'
                    : 'No sets for "' . $card['exercise'] . '" in the last ' . $card['weeks'] . ' weeks.',
                '_render'  => $card,
            ];
        }

        // Only a compact summary goes to the model; the card carries the full series.
        // Deliberately NO latest-vs-first "change"/percentage — the user logs many
        // sessions at varying intensity, so that difference is misleading.
        return [
            'exercise' => $card['exercise'],
            'metric'   => self::METRICS[$card['metric']],
            'weeks'    => $card['weeks'],
            'unit'     => $card['unit'],
            'sessions' => $card['summary']['sessions'],
            'best'     => $card['summary']['best'],
            'latest'   => $card['summary']['last'],
            'note'     => 'Do NOT frame the latest-vs-first difference as progress, and do NOT give a '
                . 'percentage change — the user logs many sessions at varying intensity, so a lighter '
                . 'recent session does not mean they got weaker. Talk about the peak (best) and the '
                . 'overall trend the chart shows. For est_1rm, 1-rep points are tested maxes (diamonds); '
                . 'multi-rep points are Epley estimates.',
            '_render'  => $card,
        ];
    }

    /**
     * Builds the progression card payload. Shared by the tool and the card's own
     * interactive endpoint so both produce an identical shape.
     *
     * @return array<string, mixed>
     */
    public static function buildCard(
        Workouts $workouts,
        int $userId,
        ?string $exercise,
        string $metric,
        int $weeks,
        ?ExerciseAliases $aliases = null
    ): array {
        $metric = isset(self::METRICS[$metric]) ? $metric : self::DEFAULT_METRIC;
        $weeks  = in_array($weeks, self::RANGES, true) ? $weeks : self::DEFAULT_WEEKS;

        // Canonicalise an explicitly requested exercise so a variant finds its rows.
        if ($aliases !== null && $exercise !== null && $exercise !== '') {
            $exercise = $aliases->resolve($userId, $exercise);
        }

        $exercises = $workouts->distinctExercises($userId, 60);
        // Default to the most recently trained exercise; keep an explicit request even
        // if it has no rows in range (so the picker still shows what was asked for).
        if (($exercise === null || $exercise === '') && $exercises !== []) {
            $exercise = $exercises[0];
        }

        $base = [
            'kind'      => 'progression',
            'exercise'  => $exercise,
            'exercises' => $exercises,
            'metric'    => $metric,
            'metrics'   => array_map(
                static fn (string $k): array => ['key' => $k, 'label' => self::METRICS[$k]],
                array_keys(self::METRICS),
            ),
            'weeks'     => $weeks,
            'ranges'    => self::RANGES,
            'unit'      => 'kg',
            'points'    => [],
            'has_data'  => false,
        ];

        if ($exercise === null) {
            return $base;
        }

        $from = date('Y-m-d 00:00:00', strtotime("-{$weeks} weeks"));
        $sets = $workouts->getHistory($userId, $exercise, $from, null, null);

        // Aggregate to one point per calendar day (UTC date of logged_at).
        $byDay = [];
        foreach ($sets as $s) {
            $day    = substr((string) $s['logged_at'], 0, 10);
            $weight = $s['weight'] !== null ? (float) $s['weight'] : null;
            $reps   = $s['reps'] !== null ? (int) $s['reps'] : null;
            if ($weight === null) {
                continue; // bodyweight-only sets carry no load to trend
            }
            $byDay[$day] ??= ['sets' => []];
            $byDay[$day]['sets'][] = ['weight' => $weight, 'reps' => $reps];
        }
        ksort($byDay);

        $points = [];
        foreach ($byDay as $day => $bucket) {
            [$value, $detail, $real] = self::metricFor($metric, $bucket['sets']);
            if ($value === null) {
                continue;
            }
            $points[] = [
                'date'   => $day,
                'value'  => $value,
                'detail' => $detail,
                'real'   => $real, // est_1rm only: true if the point is a tested (1-rep) max
            ];
        }

        if ($points === []) {
            return $base;
        }

        $values = array_column($points, 'value');
        $first  = $points[0]['value'];
        $last   = $points[count($points) - 1]['value'];
        $delta  = round($last - $first, 1);
        $pct    = $first > 0 ? (int) round(($delta / $first) * 100) : 0;

        $base['has_data'] = true;
        $base['points']   = $points;
        $base['summary']  = [
            'first'    => $first,
            'last'     => $last,
            'best'     => max($values),
            'delta'    => $delta,
            'pct'      => $pct,
            'sessions' => count($points),
        ];

        return $base;
    }

    /**
     * Computes a day's value + a short human detail for the chosen metric from its sets.
     * The third element is the "real" flag: for est_1rm it's true when the best value
     * comes from an actual 1-rep set (a tested max, not an Epley estimate); always false
     * for the other metrics.
     *
     * @param array<int, array{weight: float, reps: int|null}> $sets
     * @return array{0: float|null, 1: string, 2: bool}
     */
    private static function metricFor(string $metric, array $sets): array
    {
        if ($metric === 'volume') {
            $vol = 0.0;
            foreach ($sets as $s) {
                if ($s['reps'] !== null) {
                    $vol += $s['weight'] * $s['reps'];
                }
            }
            return $vol > 0 ? [round($vol, 1), self::kg($vol) . ' total', false] : [null, '', false];
        }

        if ($metric === 'top_weight') {
            $top = null;
            foreach ($sets as $s) {
                $top = $top === null ? $s['weight'] : max($top, $s['weight']);
            }
            return $top === null ? [null, '', false] : [round($top, 1), self::kg($top), false];
        }

        // est_1rm — best across the day's sets (needs reps). A 1-rep set IS the 1RM
        // (use the weight as-is); multi-rep sets are Epley-estimated.
        $best       = null;
        $bestWeight = 0.0;
        $bestReps   = 0;
        foreach ($sets as $s) {
            if ($s['reps'] === null || $s['reps'] < 1) {
                continue;
            }
            $est = $s['reps'] === 1 ? $s['weight'] : $s['weight'] * (1 + $s['reps'] / 30);
            if ($best === null || $est > $best) {
                $best       = $est;
                $bestWeight = $s['weight'];
                $bestReps   = $s['reps'];
            }
        }
        return $best === null
            ? [null, '', false]
            : [round($best, 1), self::kg($bestWeight) . ' × ' . $bestReps, $bestReps === 1];
    }

    private static function kg(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
        return $s . ' kg';
    }
}
