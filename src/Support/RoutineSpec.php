<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\Places;
use App\Data\Routines;

/**
 * Turns what the model passes to create_routine / update_routine into a clean routine spec, or an error it can act on.
 * Places are resolved by NAME against the user's saved places (their own shapes decide what "at home" means).
 */
final class RoutineSpec
{
    /** JSON-schema properties the routine tools share. @return array<string, array<string, mixed>> */
    public static function properties(): array
    {
        return [
            'name'        => ['type' => 'string', 'description' => 'A short name, e.g. "Leaving".'],
            'instruction' => ['type' => 'string', 'description' => 'WHAT TO DO each time, self-contained (nobody is watching): e.g. "Turn off all lights and set the heating to 17 °C, then say what you did." The assistant runs it with all its tools.'],
            'phrases'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'What the user says or types to run it, in the languages they use: ["I\'m leaving", "jeg smutter"].'],
            'trigger_event' => ['type' => 'string', 'enum' => ['leave', 'enter'], 'description' => 'For a LOCATION routine: run when the user leaves or arrives. Needs trigger_place or trigger_place_type.'],
            'trigger_place' => ['type' => 'string', 'description' => 'The name of one of the user\'s saved places (list_places), e.g. "Home".'],
            'trigger_place_type' => ['type' => 'string', 'description' => 'Or any place of a type: home, work, business, commute, private, other.'],
            'conditions'  => [
                'type' => 'object',
                'description' => 'Optional. When it should (not) run. weekdays: ISO days 1=Mon..7=Sun. between: ["07:00","22:00"] local. cooldown_minutes. away: only when the user is NOT at home. others_away: only when nobody else in the household is home.',
                'properties' => [
                    'weekdays' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'between'  => ['type' => 'array', 'items' => ['type' => 'string']],
                    'cooldown_minutes' => ['type' => 'integer'],
                    'away'     => ['type' => 'boolean'],
                    'others_away' => ['type' => 'boolean'],
                ],
            ],
            'mode'        => ['type' => 'string', 'enum' => ['auto', 'ask'], 'description' => '"auto" (default): runs by itself when triggered by a place or the clock. "ask": sends a question first and runs only after the user says yes. Saying the phrase always runs it at once.'],
        ];
    }

    /** @return array<int, string> the user's saved places as id → name */
    public static function placeNames(Places $places, int $userId): array
    {
        $out = [];
        foreach ($places->list($userId) as $p) {
            $out[(int) $p['id']] = (string) $p['name'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $a the tool arguments
     * @return array{error?:string, fields?:array<string, mixed>, place?:?array<string, mixed>}
     */
    public static function parse(array $a, Places $places, int $userId): array
    {
        $f = [];
        if (isset($a['name']) && trim((string) $a['name']) !== '') {
            $f['label'] = trim((string) $a['name']);
        }
        if (isset($a['instruction']) && trim((string) $a['instruction']) !== '') {
            $f['instruction'] = trim((string) $a['instruction']);
        }
        if (isset($a['phrases']) && is_array($a['phrases'])) {
            $f['phrases'] = array_values(array_map('strval', $a['phrases']));
        }
        if (isset($a['conditions']) && is_array($a['conditions'])) {
            $f['conditions'] = RoutineGate::normalize($a['conditions']);
        }
        if (isset($a['mode']) && in_array($a['mode'], ['auto', 'ask'], true)) {
            $f['run_mode'] = (string) $a['mode'];
        }

        $placeName = trim((string) ($a['trigger_place'] ?? ''));
        $placeType = trim((string) ($a['trigger_place_type'] ?? ''));
        if ($placeName !== '' || $placeType !== '' || isset($a['trigger_event'])) {
            $ids = [];
            if ($placeName !== '') {
                $p = $places->findByName($userId, $placeName);
                if ($p === null) {
                    $names = array_map(static fn (array $x): string => (string) $x['name'], $places->list($userId));

                    return ['error' => 'No saved place called "' . $placeName . '"' . ($names !== [] ? ' (they have: ' . implode(', ', $names) . ')' : '')
                        . '. Save it first (save_place), or use a place type.'];
                }
                $ids[] = (int) $p['id'];
            }
            $trigger = Routines::normalizePlaceTrigger([
                'event' => $a['trigger_event'] ?? 'leave', 'place_ids' => $ids, 'place_types' => $placeType !== '' ? [$placeType] : [],
            ]);
            if ($trigger === null) {
                return ['error' => 'A location routine needs trigger_place (a saved place) or trigger_place_type.'];
            }
            $f['trigger_config'] = $trigger;
        }

        return ['fields' => $f];
    }
}
