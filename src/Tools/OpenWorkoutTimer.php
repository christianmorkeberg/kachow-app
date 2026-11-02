<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * Tool: open the workout-timer card (EMOM, countdown timer or stopwatch), optionally filled in. The timer itself runs in the
 * app on the user's device (assets/workout-timer.js) — this only opens the card; the user presses Start (a tap is needed
 * for sound and keeping the screen on).
 */
final class OpenWorkoutTimer implements Tool
{
    public function name(): string
    {
        return 'open_workout_timer';
    }

    public function description(): string
    {
        return 'Opens the workout timer card, optionally filled in, for "start an EMOM", "EMOM 12 minutes, 40 seconds work 20 rest", '
            . '"set a 10 minute timer", "countdown 90 seconds", "stopwatch", "interval timer". mode: "emom" (every minute on the minute: '
            . 'work seconds + rest seconds per round, for a number of rounds OR a total time — a classic EMOM is 60 s work, 0 rest), '
            . '"timer" (countdown) or "stopwatch". The user presses Start on the card themselves (you cannot start it). This is NOT a '
            . 'reminder or an alarm at a clock time (use set_reminder for that) and does not log a workout.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'mode'          => ['type' => 'string', 'enum' => ['emom', 'timer', 'stopwatch'], 'description' => 'Which timer to open.'],
                'work_seconds'  => ['type' => 'integer', 'description' => 'EMOM: seconds of work in each round (5–900). Classic EMOM = 60.'],
                'rest_seconds'  => ['type' => 'integer', 'description' => 'EMOM: seconds of rest in each round (0–900). Classic EMOM = 0.'],
                'rounds'        => ['type' => 'integer', 'description' => 'EMOM: number of rounds (1–200). Use this OR total_minutes.'],
                'total_minutes' => ['type' => 'integer', 'description' => 'EMOM: total time in minutes (1–240); the rounds follow from it. Use this OR rounds.'],
                'minutes'       => ['type' => 'integer', 'description' => 'Timer: minutes to count down (0–600).'],
                'seconds'       => ['type' => 'integer', 'description' => 'Timer: extra seconds (0–59).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $mode = (string) ($arguments['mode'] ?? '');
        if (!in_array($mode, ['emom', 'timer', 'stopwatch'], true)) {
            $mode = isset($arguments['work_seconds']) || isset($arguments['rest_seconds']) || isset($arguments['rounds']) || isset($arguments['total_minutes'])
                ? 'emom' : (isset($arguments['minutes']) || isset($arguments['seconds']) ? 'timer' : 'emom');
        }
        $int = static fn (string $k, int $lo, int $hi): ?int => isset($arguments[$k]) && is_numeric($arguments[$k]) ? max($lo, min($hi, (int) round((float) $arguments[$k]))) : null;

        $card = ['kind' => 'workout_timer', 'mode' => $mode];
        if ($mode === 'emom') {
            $card['work'] = $int('work_seconds', 5, 900);
            $card['rest'] = $int('rest_seconds', 0, 900);
            // Rounds win over total time when both are given (one is the answer, the other follows from it).
            $card['rounds']        = $int('rounds', 1, 200);
            $card['total_minutes'] = $card['rounds'] === null ? $int('total_minutes', 1, 240) : null;
        } elseif ($mode === 'timer') {
            $card['minutes'] = $int('minutes', 0, 600);
            $card['seconds'] = $int('seconds', 0, 59);
        }
        $card = array_filter($card, static fn (mixed $v): bool => $v !== null);

        return [
            'opened' => true,
            'mode'   => $mode,
            'settings' => array_diff_key($card, ['kind' => 1]),
            'note'   => 'The timer card is open. Tell the user to press Start (it needs their tap for sound). It runs on their device.',
            '_render' => $card,
        ];
    }
}
