<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Routines;
use App\Support\RoutineSteps;

/**
 * Tool: put things back the way they were before a routine's last run — "undo that", "fortryd", "I'm home, put the lights back".
 * Works for routines that run recorded steps: each run keeps the lights' / rooms' state from just before it changed them
 * (on / off, brightness, colour temperature), for 24 hours. Scenes and anything but Hue lights cannot be restored.
 */
final class UndoRoutine implements Tool
{
    public function __construct(private Routines $routines, private Tool $home)
    {
    }

    public function name(): string
    {
        return 'undo_routine';
    }

    public function description(): string
    {
        return 'Undoes the last run of a routine or scheduled task: puts its lights / rooms back to how they were just BEFORE it ran (on or off, '
            . 'brightness, colour temperature). Use for "undo that", "put it back", "fortryd", "turn the lights back like they were". Pass `routine` '
            . '(name or id) or leave it out for the most recent run. Only runs that replayed recorded steps within the last 24 hours can be undone; '
            . 'if there is nothing to undo, say so — do not guess the old state yourself.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['routine' => ['type' => 'string', 'description' => 'Name or id of the routine; omit for the most recent run.']],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $q = trim((string) ($arguments['routine'] ?? ''));
        $r = $q === '' ? $this->routines->lastUndoable($userId) : ($this->routines->find($userId, $q) ?? $this->routines->findAnyStatus($userId, $q));
        if ($r === null) {
            return ['undone' => false, 'note' => $q === '' ? 'Nothing has run recently that can be undone.' : 'No routine matches "' . $q . '".'];
        }
        $undo = $this->routines->takeUndo($userId, $r['id']);
        if ($undo === []) {
            return ['undone' => false, 'routine' => $r['label'], 'note' => 'There is nothing to undo for this one (it has not run with recorded steps in the last 24 hours, or it was already undone).'];
        }
        $out = RoutineSteps::undo($undo, fn (string $tool, array $args): array => $this->home->execute($args, $userId));

        return [
            'undone'  => $out['failed'] === 0,
            'routine' => $r['label'],
            'restored' => $out['done'],
            'failed'  => $out['failed'],
            'note'    => $out['failed'] === 0 ? 'Put back. Say so in a few words.' : 'Some of it could not be reached (the home agent may be offline): tell the user.',
        ];
    }
}
