<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Conversations;
use App\Data\Places;
use App\Data\Routines;
use App\Data\ScheduledTasks;
use App\Support\RoutineSpec;

/**
 * Tool: build a routine from what the user said — "when I say I'm leaving, turn off the lights and set the heating to 17",
 * "when I leave home, turn off the lights (tell me first)". It is saved as a DRAFT the user confirms on the card (or by
 * saying yes → update_routine status "active"); with save=true it is live at once.
 */
final class CreateRoutine implements Tool
{
    public const MAX_PER_USER = 20;

    public function __construct(private Routines $routines, private ScheduledTasks $tasks, private Conversations $conversations, private Places $places)
    {
    }

    public function name(): string
    {
        return 'create_routine';
    }

    public function description(): string
    {
        return 'Creates a ROUTINE: something the assistant does for the user when they SAY a phrase ("I\'m leaving" → lights off, heating down), '
            . 'when they LEAVE or ARRIVE at a saved place, or both — optionally only under conditions (weekdays, a time window, only when away, '
            . 'only if nobody else is home) and optionally asking first. Use for "make a routine…", "when I say X, do Y", "when I leave home, …", '
            . '"når jeg forlader …, så …", "lav en rutine". Give a name, an `instruction` (exactly what to do, self-contained — the future run has '
            . 'all your tools but nobody is watching), the `phrases` the user will say, and for a location routine trigger_event + trigger_place (or '
            . 'trigger_place_type). It is saved as a DRAFT and the card shows it for the user to Save — tell them to tap Save, or save=true when they '
            . 'already said "yes, save it". For a time schedule ("every weekday at 7") use schedule_task instead. Never put irreversible actions '
            . '(deleting, sending, unlocking) in a routine unless the user explicitly asked for them.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => RoutineSpec::properties() + [
                'save' => ['type' => 'boolean', 'description' => 'True to make it live immediately (the user already confirmed); default false = a draft for the card.'],
            ],
            'required' => ['name', 'instruction'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $parsed = RoutineSpec::parse($arguments, $this->places, $userId);
        if (isset($parsed['error'])) {
            return ['error' => $parsed['error']];
        }
        $f = $parsed['fields'];
        if (($f['label'] ?? '') === '' || ($f['instruction'] ?? '') === '') {
            return ['error' => 'A routine needs a name and an instruction (what to do).'];
        }
        $isPlace  = isset($f['trigger_config']);
        $phrases  = $f['phrases'] ?? [];
        if (!$isPlace && $phrases === []) {
            return ['error' => 'A routine needs something to start it: the phrases the user will say, or a place to leave / arrive at.'];
        }
        if ($this->routines->countAll($userId) >= self::MAX_PER_USER) {
            return ['error' => 'They already have ' . self::MAX_PER_USER . ' routines — remove one first.'];
        }

        $save = filter_var($arguments['save'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $conv = $this->conversations->start($userId);
        $this->conversations->setTitle($userId, $conv, 'Routine: ' . $f['label']);
        $id = $this->routines->create($userId, $conv, [
            'label' => $f['label'], 'instruction' => $f['instruction'], 'trigger_type' => $isPlace ? 'place' : 'phrase',
            'phrases' => $phrases, 'trigger_config' => $f['trigger_config'] ?? [], 'conditions' => $f['conditions'] ?? [],
            'run_mode' => $f['run_mode'] ?? 'auto', 'status' => $save ? 'active' : 'draft',
        ]);

        return [
            'created' => true,
            'id'      => $id,
            'status'  => $save ? 'active' : 'draft',
            'routine' => $this->routines->summary($userId, $id, RoutineSpec::placeNames($this->places, $userId)),
            '_render' => $this->tasks->cardForUser($userId, $this->places),
            'note'    => $save
                ? 'It is live. Confirm in one short sentence what triggers it and what it does.'
                : 'It is a DRAFT: say in one short sentence what it will do and tell them to tap Save on the card (or say "save it").',
        ];
    }
}
