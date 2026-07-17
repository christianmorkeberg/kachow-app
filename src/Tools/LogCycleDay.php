<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\CycleTracker;

/**
 * Tool: log how the user feels on a day — mood and/or energy on a 1–5 scale — for
 * cycle tracking. Either value is optional; log whichever the user mentions.
 */
final class LogCycleDay implements Tool
{
    public function __construct(private CycleTracker $cycle)
    {
    }

    public function name(): string
    {
        return 'log_cycle_day';
    }

    public function description(): string
    {
        return 'Logs how the user feels today (or a given day): mood and/or energy on a 1–5 scale '
            . '(1 = very low, 5 = very high) for cycle tracking. Map words to numbers, e.g. "exhausted/'
            . 'drained" ≈ energy 1, "great/loads of energy" ≈ 5; "awful mood" ≈ mood 1, "really good '
            . 'mood" ≈ 5. Use for "my energy is low today", "I feel great", "log my mood as 4", Danish '
            . '"mit humør er lavt i dag", "jeg har meget energi", "jeg er helt drænet". Log only the '
            . 'value(s) the user actually mentions. Shows the cycle card.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'mood'   => ['type' => 'integer', 'description' => 'Mood 1–5 (1 very low … 5 very high). Omit if not mentioned.'],
                'energy' => ['type' => 'integer', 'description' => 'Energy 1–5 (1 very low … 5 very high). Omit if not mentioned.'],
                'date'   => ['type' => 'string', 'description' => 'Local date YYYY-MM-DD. Defaults to today.'],
                'note'   => ['type' => 'string', 'description' => 'Optional short note.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $mood   = isset($arguments['mood']) && $arguments['mood'] !== '' ? (int) $arguments['mood'] : null;
        $energy = isset($arguments['energy']) && $arguments['energy'] !== '' ? (int) $arguments['energy'] : null;

        if ($mood === null && $energy === null) {
            return ['error' => 'Tell me a mood or energy level (1–5) to log.'];
        }

        $this->cycle->logDay(
            $userId,
            isset($arguments['date']) ? (string) $arguments['date'] : '',
            $mood,
            $energy,
            isset($arguments['note']) ? (string) $arguments['note'] : null,
        );

        return [
            'logged'  => true,
            'mood'    => $mood,
            'energy'  => $energy,
            '_render' => $this->cycle->card($userId),
        ];
    }
}
