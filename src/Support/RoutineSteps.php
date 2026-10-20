<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A routine's recorded exact steps. The first run of an instruction goes through the model; if everything it did was
 * home-device WRITES (Hue lights, rooms, scenes), those exact calls are kept and later runs replay them directly — about a
 * second instead of 10–20, and the same result every time. Anything else (a weather check, an email, a calendar read) means the
 * instruction needs judgement, so nothing is recorded and it stays a model run.
 *
 * Replaying also takes a before-state snapshot of each light / room it is about to change, which is what "undo" restores.
 * Pure: the device calls go through a callable, so it is testable without a Pico.
 */
final class RoutineSteps
{
    public const TOOL = 'home_device_call';
    public const MAX_STEPS = 20;

    /** Hue resources whose state can be read back and restored. */
    private const RESTORABLE = '#^/clip/v2/resource/(light|grouped_light)/[A-Za-z0-9-]+$#';

    /**
     * The steps worth recording from one run's tool calls, or null if the run was not purely home-device writes.
     *
     * @param list<array{name:string, args:array<string, mixed>, ok:bool}> $calls
     * @return list<array{device:string, method:string, path:string, body?:array<string, mixed>}>|null
     */
    public static function record(array $calls): ?array
    {
        $steps = [];
        foreach ($calls as $c) {
            if ($c['name'] === 'notify_me') {
                continue;
            }
            if ($c['name'] !== self::TOOL) {
                return null;                   // a read of anything else, or another action: needs the model's judgement
            }
            $a      = $c['args'];
            $method = strtoupper((string) ($a['method'] ?? 'GET'));
            if ($method === 'GET') {
                continue;                      // looking an id up; the id is in the write that follows
            }
            if (!$c['ok'] || !in_array($method, ['PUT', 'POST'], true) || (string) ($a['path'] ?? '') === '' || (string) ($a['device'] ?? '') === '') {
                return null;                   // a failed or odd write: never freeze half a job
            }
            $step = ['device' => (string) $a['device'], 'method' => $method, 'path' => (string) $a['path']];
            if (isset($a['body']) && is_array($a['body'])) {
                $step['body'] = $a['body'];
            }
            $steps[] = $step;
        }

        return $steps === [] || count($steps) > self::MAX_STEPS ? null : $steps;
    }

    /**
     * Whether an instruction decides something ("if the kitchen light is on …", "only when …", "hvis …"): then what the model did
     * on one run is not what it would do on the next, so nothing is recorded.
     */
    public static function conditional(string $instruction): bool
    {
        return preg_match('/\b(if|unless|only|whether|depending|else|otherwise|hvis|kun|medmindre|ellers|afhængig)\b/iu', $instruction) === 1;
    }

    /** The GET that reads a step's target back before changing it, or null when it cannot be restored (a scene, another device). */
    public static function snapshotCall(array $step): ?array
    {
        if (strtolower((string) ($step['device'] ?? '')) !== 'hue' || strtoupper((string) ($step['method'] ?? '')) !== 'PUT' || preg_match(self::RESTORABLE, (string) $step['path']) !== 1) {
            return null;
        }

        return ['device' => (string) $step['device'], 'method' => 'GET', 'path' => (string) $step['path']];
    }

    /**
     * The step that puts a light / room back the way a snapshot GET found it: on / off, brightness, colour temperature.
     *
     * @param array<string, mixed> $step   the step that was about to run
     * @param array<string, mixed> $before the home_device_call result of snapshotCall()
     * @return array<string, mixed>|null
     */
    public static function restoreStep(array $step, array $before): ?array
    {
        $item = $before['response']['data'][0] ?? null;
        if (!is_array($item) || !isset($item['on']['on'])) {
            return null;
        }
        $body = ['on' => ['on' => (bool) $item['on']['on']]];
        if (isset($item['dimming']['brightness']) && is_numeric($item['dimming']['brightness'])) {
            $body['dimming'] = ['brightness' => (float) $item['dimming']['brightness']];
        }
        if (isset($item['color_temperature']['mirek']) && is_numeric($item['color_temperature']['mirek'])) {
            $body['color_temperature'] = ['mirek' => (int) $item['color_temperature']['mirek']];
        }

        return ['device' => (string) $step['device'], 'method' => 'PUT', 'path' => (string) $step['path'], 'body' => $body];
    }

    /**
     * Runs the steps through $call(tool, args) → result. Returns what happened and the steps that undo it (newest first).
     *
     * @param list<array<string, mixed>>                      $steps
     * @param callable(string, array<string, mixed>): array<string, mixed> $call
     * @return array{ok:bool, done:int, undo:list<array<string, mixed>>, unrestorable:int, error:?string}
     */
    public static function replay(array $steps, callable $call): array
    {
        $undo = [];
        $unrestorable = 0;
        $done = 0;
        foreach ($steps as $step) {
            $snap = self::snapshotCall($step);
            $restore = null;
            if ($snap !== null) {
                $restore = self::restoreStep($step, $call(self::TOOL, $snap));
            }
            $result = $call(self::TOOL, $step);
            if (isset($result['error'])) {
                return ['ok' => false, 'done' => $done, 'undo' => array_reverse($undo), 'unrestorable' => $unrestorable, 'error' => (string) $result['error']];
            }
            $done++;
            if ($restore !== null) {
                $undo[] = $restore;
            } else {
                $unrestorable++;
            }
        }

        return ['ok' => true, 'done' => $done, 'undo' => array_reverse($undo), 'unrestorable' => $unrestorable, 'error' => null];
    }

    /**
     * Puts things back: runs the undo steps. Returns how many worked.
     *
     * @param list<array<string, mixed>> $undo
     * @param callable(string, array<string, mixed>): array<string, mixed> $call
     * @return array{done:int, failed:int}
     */
    public static function undo(array $undo, callable $call): array
    {
        $done = $failed = 0;
        foreach ($undo as $step) {
            isset($call(self::TOOL, $step)['error']) ? $failed++ : $done++;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /** Whether a home-device error means the home agent could not be reached (worth one retry later). */
    public static function unreachable(string $error): bool
    {
        return str_contains($error, 'never connected') || str_contains($error, 'is offline') || str_contains($error, 'No answer from the home agent');
    }

    /** One line for the card / the model: "3 recorded steps (2 can be undone)". */
    public static function describe(array $steps, array $undo = []): string
    {
        $n = count($steps);

        return $n . ' recorded step' . ($n === 1 ? '' : 's') . ($undo !== [] ? ' · can be undone' : '');
    }
}
