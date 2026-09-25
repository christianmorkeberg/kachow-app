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
            . 'noted it. Shows the cycle card. Also for the CURRENT period, WITHOUT start_date: '
            . '"I\'m still bleeding / my period is still going" (Danish "jeg har stadig menstruation", '
            . '"den er stadig i gang", "bløder stadig") → still_ongoing=true, which extends the current '
            . 'period through today so the card stays in Winter (menstrual) — NEVER log a new start for '
            . 'that; "my period ended yesterday" ("den stoppede i går") → end_date only. The phase follows '
            . 'the logged end, so after extending just confirm it; the card updates itself.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'start_date' => ['type' => 'string', 'description' => 'When the period started, "YYYY-MM-DD". Defaults to today.'],
                'end_date'   => ['type' => 'string', 'description' => 'When it ended, "YYYY-MM-DD" (optional).'],
                'still_ongoing' => ['type' => 'boolean', 'description' => 'true = the CURRENT period is still going today (extends it; do not pass start_date).'],
                'note'       => ['type' => 'string', 'description' => 'Optional short note (symptoms etc.).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $start   = trim((string) ($arguments['start_date'] ?? ''));
        $end     = trim((string) ($arguments['end_date'] ?? ''));
        $ongoing = filter_var($arguments['still_ongoing'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Current period only (no new start): extend it through today, or set its end.
        if ($start === '' && ($ongoing || $end !== '')) {
            $span = $this->cycle->setCurrentPeriodEnd($userId, $ongoing ? null : $end, $ongoing);
            if ($span === null) {
                return ['logged' => false, 'error' => 'No period started in the last two weeks to update — '
                    . 'ask when this period started and log that start_date.'];
            }
            $card = $this->cycle->card($userId);

            return [
                'logged'  => true,
                'period'  => $span + ['ongoing' => $ongoing],
                'status'  => $this->summary($card),
                '_render' => $card,
            ];
        }

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
            'period_days' => $card['period_length'],
            'period_ongoing' => $card['period_ongoing'] ?? false,
            'phase'       => $card['phase_label'],
            'season'      => $card['season_label'] ?? null,
            'next_period' => $card['next_period'],
            'days_until'  => $card['days_until'],
            'fertile'     => $card['in_fertile'],
        ];
    }
}
