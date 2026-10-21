<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\TrainingPrograms;
use App\Support\ProgramInput;

/**
 * Tool: drop days from a training programme — one weekday of a week, a whole week, or a weekday in every week. (Plans already made from
 * those days stay: the plan card is the user's own once it exists.)
 */
final class RemoveProgramDays implements Tool
{
    public function __construct(private TrainingPrograms $programs)
    {
    }

    public function name(): string
    {
        return 'remove_program_days';
    }

    public function description(): string
    {
        return 'Removes days from a training programme: give `week` (and optionally `weekday`) to drop one day or a whole week, or only `weekday` to drop that weekday '
            . 'from every week. Plans already created from those days are not changed. Use for "take Friday out of week 44", "drop week 46".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'program' => ['type' => 'string', 'description' => 'Programme name or id.'],
                'week'    => ['type' => 'string', 'description' => 'The week number (43).'],
                'weekday' => ['type' => 'string', 'description' => '1–7 or a weekday name.'],
            ],
            'required' => ['program'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $p = $this->programs->find($userId, (string) ($arguments['program'] ?? ''));
        if ($p === null) {
            return ['error' => 'No programme matches "' . ($arguments['program'] ?? '') . '".'];
        }
        $week    = isset($arguments['week']) ? ProgramInput::week($arguments['week']) : null;
        $weekday = isset($arguments['weekday']) ? ProgramInput::weekday($arguments['weekday']) : null;
        if ($week === null && $weekday === null) {
            return ['error' => 'Say which week and / or weekday to remove (to remove the whole programme use delete_training_program).'];
        }

        return ['removed_days' => $this->programs->removeDays($userId, $p['id'], $week, $weekday), 'program' => $p['name']];
    }
}
