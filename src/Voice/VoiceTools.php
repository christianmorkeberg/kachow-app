<?php

declare(strict_types=1);

namespace App\Voice;

use App\Tools\ToolRegistry;

/**
 * Which tools the voice assistant gets, and how each is declared to Gemini Live.
 *
 * A fixed allow-list (no deletes or bulk clears: they would need a spoken confirmation first) plus one
 * gateway tool, ask_kachow, that hands everything else to the full text assistant. Tools are FAST (answer in
 * about a second: the model waits for the result) or SLOW (may take seconds: declared NON_BLOCKING so the
 * conversation carries on and the result is spoken when the model is idle).
 */
final class VoiceTools
{
    public const GATEWAY = 'ask_kachow';

    /** Answer within about a second. */
    public const FAST = [
        'get_calendar_events', 'insert_calendar_event', 'update_calendar_event',
        'set_reminder', 'list_reminders',
        'get_shopping_list', 'add_to_shopping_list', 'check_off_item',
        'log_work_event', 'get_work_hours',
        'log_workout', 'get_workout_history', 'get_workout_plan',
    ];

    /** May take several seconds (weather service, the home agent, the full assistant). */
    public const SLOW = ['get_current_weather', 'get_weather_forecast', 'home_device_call', self::GATEWAY];

    /** @return list<string> */
    public static function allowed(): array
    {
        return array_merge(self::FAST, self::SLOW);
    }

    public static function isAllowed(string $name): bool
    {
        return in_array($name, self::allowed(), true);
    }

    public static function isSlow(string $name): bool
    {
        return in_array($name, self::SLOW, true);
    }

    /**
     * Live-API function declarations for the allow-list. $mode: 'auto' (slow = NON_BLOCKING, fast = BLOCKING),
     * 'nonblocking' or 'blocking' (all the same) — for experimenting with how natural it sounds.
     *
     * @return list<array<string, mixed>>
     */
    public static function declarations(ToolRegistry $registry, string $mode = 'auto'): array
    {
        $out = [];
        foreach ($registry->declarations() as $d) {
            if (!self::isAllowed((string) $d['name']) || $d['name'] === self::GATEWAY) {
                continue;
            }
            $out[] = self::withBehavior($d, $mode);
        }
        $out[] = self::withBehavior(self::gatewayDeclaration(), $mode);

        return $out;
    }

    /** @return array<string, mixed> */
    public static function gatewayDeclaration(): array
    {
        return [
            'name'        => self::GATEWAY,
            'description' => 'SLOW. Hands a request to the full Kachow assistant, which can do everything the other tools cannot '
                . '(email, expenses and receipts, mileage, bookkeeping, cycle, settings, location, vinyls, connections, memories, '
                . 'dev ideas …) and returns its answer in words. Pass the user\'s request as they said it, with any detail they gave. '
                . 'Do not use it for what a specific tool above does.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => ['request' => ['type' => 'string', 'description' => 'What the user asked for, in their words.']],
                'required'   => ['request'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $decl
     * @return array<string, mixed>
     */
    private static function withBehavior(array $decl, string $mode): array
    {
        $slow = self::isSlow((string) $decl['name']);
        $behavior = match ($mode) {
            'nonblocking' => 'NON_BLOCKING',
            'blocking'    => 'BLOCKING',
            default       => $slow ? 'NON_BLOCKING' : 'BLOCKING',
        };
        if ($slow && !str_starts_with((string) $decl['description'], 'SLOW')) {
            $decl['description'] = 'SLOW (may take several seconds). ' . $decl['description'];
        }
        $decl['behavior'] = $behavior;

        return $decl;
    }
}
