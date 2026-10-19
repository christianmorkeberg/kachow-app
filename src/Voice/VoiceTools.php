<?php

declare(strict_types=1);

namespace App\Voice;

use App\Tools\ToolRegistry;

/**
 * Which tools the voice assistant gets, and how each is declared to Gemini Live.
 *
 * A fixed allow-list (deletes are in, behind a spoken confirmation: see ConfirmGate) plus one
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
        // Blocking on purpose: a NON_BLOCKING result makes the model speak a new turn per call, so switching off
        // five lights gave five separate confirmations.
        'home_device_call',
    ];

    /**
     * Destructive, but allowed: the first call is held back by ConfirmGate until the user has said yes out loud.
     * (Receipts, trips, places, connections, plans, vinyls, memories … stay with the text assistant via ask_kachow.)
     */
    public const CONFIRM = [
        'delete_calendar_event', 'remove_from_shopping_list', 'clear_checked_items', 'delete_shopping_list',
        'cancel_reminder', 'delete_work_event',
    ];

    /** May take several seconds (weather service, the full assistant). */
    public const SLOW = ['get_current_weather', 'get_weather_forecast', self::GATEWAY];

    /** @return list<string> */
    public static function allowed(): array
    {
        return array_merge(self::FAST, self::CONFIRM, self::SLOW);
    }

    public static function isAllowed(string $name): bool
    {
        return in_array($name, self::allowed(), true);
    }

    public static function needsConfirm(string $name): bool
    {
        return in_array($name, self::CONFIRM, true);
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
        if (self::needsConfirm((string) $decl['name'])) {
            $decl['description'] = 'DESTRUCTIVE: the first call only asks; it deletes after the user says yes. ' . $decl['description'];
        }
        $decl['behavior'] = $behavior;

        return $decl;
    }
}
