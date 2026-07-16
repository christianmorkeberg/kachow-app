<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\CycleTracker;

/**
 * Tool: remove a logged period (e.g. a mistaken entry). With no date given, removes
 * the most recently started one.
 */
final class RemovePeriod implements Tool
{
    public function __construct(private CycleTracker $cycle)
    {
    }

    public function name(): string
    {
        return 'remove_period';
    }

    public function description(): string
    {
        return 'Removes a logged period — use to correct a mistaken entry ("delete that period I just '
            . 'logged", Danish "slet den menstruation jeg loggede"). With no start_date, removes the most '
            . 'recent one. Shows the updated cycle card.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'start_date' => ['type' => 'string', 'description' => 'Start date of the period to remove, "YYYY-MM-DD". Omit to remove the most recent.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $date = isset($arguments['start_date']) ? trim((string) $arguments['start_date']) : '';

        if ($date !== '') {
            $ts = strtotime($date);
            $removed = false;
            if ($ts !== false) {
                foreach ($this->cycle->listForUser($userId, 60) as $r) {
                    if ((string) $r['start_date'] === date('Y-m-d', $ts)) {
                        $removed = $this->cycle->remove($userId, (int) $r['id']);
                        break;
                    }
                }
            }
            if (!$removed) {
                return ['removed' => false, 'error' => 'No period logged on that date.'];
            }
            $removedDate = date('Y-m-d', $ts);
        } else {
            $removedDate = $this->cycle->removeLatest($userId);
            if ($removedDate === null) {
                return ['removed' => false, 'error' => 'There are no logged periods to remove.'];
            }
        }

        return [
            'removed'      => true,
            'removed_date' => $removedDate,
            '_render'      => $this->cycle->card($userId),
        ];
    }
}
