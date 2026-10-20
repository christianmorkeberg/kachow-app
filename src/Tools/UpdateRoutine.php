<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Places;
use App\Data\Routines;
use App\Data\ScheduledTasks;
use App\Support\RoutineSpec;

/**
 * Tool: change a routine — its name, what it does, its phrases, its place trigger, its conditions, whether it asks first —
 * or Save a draft (status "active"), pause it ("paused"), or remove it ("cancelled").
 */
final class UpdateRoutine implements Tool
{
    public function __construct(private Routines $routines, private ScheduledTasks $tasks, private Places $places)
    {
    }

    public function name(): string
    {
        return 'update_routine';
    }

    public function description(): string
    {
        return 'Changes one of the user\'s routines (made with create_routine): rename it, change what it does (`instruction`), its `phrases`, '
            . 'its place trigger (trigger_event + trigger_place / trigger_place_type), its `conditions`, or `mode` ("ask" = ask first, "auto"). '
            . 'Also: status "active" SAVES a draft (use it when the user says "yes, save it") or resumes a paused one, "paused" pauses it, '
            . '"cancelled" removes it. Identify it by `routine` (its name, a phrase, or its id). Only the fields you pass are changed — passing '
            . '`phrases` or `conditions` REPLACES the old list, so include the ones to keep. The routine card shows the result.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['routine' => ['type' => 'string', 'description' => 'Name, phrase or id of the routine.']]
                + RoutineSpec::properties()
                + ['status' => ['type' => 'string', 'enum' => ['active', 'paused', 'cancelled'], 'description' => 'active = Save / resume, paused, cancelled = remove.']],
            'required' => ['routine'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $q = trim((string) ($arguments['routine'] ?? ''));
        // A draft or paused routine is not "active", so look through all of the user's routines by name / id too.
        $r = $this->routines->find($userId, $q) ?? $this->routines->findAnyStatus($userId, $q);
        if ($r === null) {
            return ['error' => 'I could not find a routine called "' . $q . '". list_scheduled_tasks shows them.'];
        }
        $parsed = RoutineSpec::parse($arguments, $this->places, $userId);
        if (isset($parsed['error'])) {
            return ['error' => $parsed['error']];
        }
        $fields = $parsed['fields'];
        if (isset($fields['trigger_config']) && ($fields['trigger_kind'] ?? '') !== $r['trigger_type']) {
            return ['error' => 'That is a ' . $r['trigger_type'] . ' routine — a different kind of trigger needs a new routine.'];
        }
        $this->routines->update($userId, $r['id'], $fields);

        $status = (string) ($arguments['status'] ?? '');
        if ($status !== '') {
            if (!in_array($status, ['active', 'paused', 'cancelled'], true) || !$this->routines->setStatus($userId, $r['id'], $status)) {
                return ['error' => 'status must be active, paused or cancelled.'];
            }
        }

        return [
            'updated' => true,
            'routine' => $this->routines->summary($userId, $r['id'], RoutineSpec::placeNames($this->places, $userId)),
            '_render' => $this->tasks->cardForUser($userId, $this->places),
        ];
    }
}
