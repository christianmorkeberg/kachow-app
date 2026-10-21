<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\TrainingPrograms;

/**
 * Tool: delete a whole training programme (its days and exercises). Plans already made from it stay. Ask the user first.
 */
final class DeleteTrainingProgram implements Tool
{
    public function __construct(private TrainingPrograms $programs)
    {
    }

    public function name(): string
    {
        return 'delete_training_program';
    }

    public function description(): string
    {
        return 'Deletes a whole training programme for good (archive it with update_training_program instead if they may want it again). Plans already made from it '
            . 'stay. Only after the user has said so.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['program' => ['type' => 'string', 'description' => 'Programme name or id.']], 'required' => ['program']];
    }

    public function execute(array $arguments, int $userId): array
    {
        $p = $this->programs->find($userId, (string) ($arguments['program'] ?? ''));
        if ($p === null) {
            return ['error' => 'No programme matches "' . ($arguments['program'] ?? '') . '".'];
        }

        return ['deleted' => $this->programs->delete($userId, $p['id']), 'program' => $p['name']];
    }
}
