<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\CycleTracker;

/**
 * Tool: report the current cycle status — cycle day, phase, predicted next period,
 * and the fertile-window ESTIMATE. Predictions are for planning only, never medical
 * or contraceptive advice; frame the fertile window that way.
 */
final class GetCycleStatus implements Tool
{
    public function __construct(private CycleTracker $cycle)
    {
    }

    public function name(): string
    {
        return 'get_cycle_status';
    }

    public function description(): string
    {
        return 'Reports the menstrual cycle status: current cycle day, phase, the predicted next period '
            . 'and the estimated fertile window. Use for "when is my next period?", "what day of my cycle '
            . 'am I on?", "am I fertile now?", Danish "hvornår kommer min næste menstruation?", "hvilken '
            . 'dag i cyklussen er jeg på?". Predictions are ESTIMATES for planning, not contraception. '
            . 'The app frames phases as inner seasons (Winter=menstrual, Spring=follicular, Summer='
            . 'ovulation, Autumn=luteal) — you may name the season, but write it plainly and confidently; '
            . 'never add placeholder characters like "??". Shows the cycle card.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => (object) [],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $card = $this->cycle->card($userId);

        if (empty($card['has_data'])) {
            return [
                'has_data' => false,
                'message'  => 'No periods logged yet — log a period start to begin predictions.',
                '_render'  => $card,
            ];
        }

        return [
            'cycle_day'    => $card['cycle_day'],
            'phase'        => $card['phase_label'],
            'season'       => $card['season_label'] ?? null,
            'cycle_length' => $card['cycle_length'],
            'next_period'  => $card['next_period'],
            'days_until'   => $card['days_until'],
            'fertile_from' => $card['fertile_from'],
            'fertile_to'   => $card['fertile_to'],
            'in_fertile'   => $card['in_fertile'],
            'predicted'    => $card['predicted'],
            'disclaimer'   => 'Estimate for planning only — not a reliable form of contraception.',
            '_render'      => $card,
        ];
    }
}
