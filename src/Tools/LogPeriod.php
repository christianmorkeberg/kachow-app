<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\CycleTracker;

/**
 * Tool: log that a menstrual period started (and optionally ended), with optional
 * flow. Drives cycle predictions. Personal health data — always call this tool when
 * the user says their period started; never just acknowledge it.
 */
final class LogPeriod implements Tool
{
    public function __construct(private CycleTracker $cycle)
    {
    }

    public function name(): string
    {
        return 'log_period';
    }

    public function description(): string
    {
        return 'Logs that a menstrual period started (and optionally when it ended). Use for messages '
            . 'like "my period started today", "I got my period yesterday", Danish "min menstruation '
            . 'startede i dag", "jeg har fået min menstruation", "min periode begyndte". start_date '
            . 'defaults to today if not given. ALWAYS actually call this tool — do not just say you '
            . 'noted it. Shows the cycle card.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'start_date' => ['type' => 'string', 'description' => 'When the period started, "YYYY-MM-DD". Defaults to today.'],
                'end_date'   => ['type' => 'string', 'description' => 'When it ended, "YYYY-MM-DD" (optional).'],
                'note'       => ['type' => 'string', 'description' => 'Optional short note (symptoms etc.).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $this->cycle->logPeriod(
            $userId,
            isset($arguments['start_date']) ? (string) $arguments['start_date'] : '',
            isset($arguments['end_date']) ? (string) $arguments['end_date'] : null,
            null,
            isset($arguments['note']) ? (string) $arguments['note'] : null,
        );

        $card = $this->cycle->card($userId);

        return [
            'logged'  => true,
            'status'  => $this->summary($card),
            '_render' => $card,
        ];
    }

    /** @param array<string, mixed> $card */
    private function summary(array $card): array
    {
        if (empty($card['has_data'])) {
            return ['has_data' => false];
        }

        return [
            'cycle_day'   => $card['cycle_day'],
            'phase'       => $card['phase_label'],
            'season'      => $card['season_label'] ?? null,
            'next_period' => $card['next_period'],
            'days_until'  => $card['days_until'],
            'fertile'     => $card['in_fertile'],
        ];
    }
}
