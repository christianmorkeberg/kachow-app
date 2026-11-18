<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkSchedule;

/** Tool: remove days off that were marked (by a date range). */
final class DeleteWorkAbsence implements Tool
{
    public function __construct(private WorkSchedule $schedule)
    {
    }

    public function name(): string
    {
        return 'delete_work_absence';
    }

    public function description(): string
    {
        return 'Removes marked days off between `from` and `to` (YYYY-MM-DD; `to` defaults to `from`), e.g. a holiday that was cancelled or a public holiday suggestion accepted by mistake. '
            . 'Say which days were removed. The balance updates by itself.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => WorkSchedule::KINDS, 'description' => 'Only this kind.']],
            'required'   => ['from'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $from = (string) ($arguments['from'] ?? '');
        $to   = (string) ($arguments['to'] ?? $from);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            return ['deleted' => false, 'error' => 'Give from (and to) as YYYY-MM-DD.'];
        }
        $kind = isset($arguments['kind']) ? (string) $arguments['kind'] : null;
        $ids  = [];
        foreach ($this->schedule->absences($userId, $from, $to) as $a) {
            if ($kind === null || $a['kind'] === $kind) {
                $ids[] = $a['id'];
            }
        }
        $gone = $this->schedule->deleteAbsences($userId, $ids);

        return ['deleted' => $gone !== [], 'days_removed' => count($gone), 'dates' => array_column($gone, 'absence_date')];
    }
}
