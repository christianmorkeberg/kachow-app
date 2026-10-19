<?php

declare(strict_types=1);

namespace App\Voice;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The one readable line shown under a voice reply for each tool call that ran ("Turned off Hallway", "Created event
 * “Dentist” · Thu 22 Oct 10:00"). Built from the call itself, never from what the model says. Known tools get a
 * sentence; anything else gets a humanised name plus the few arguments that read like words (ids, paths and JSON are hidden).
 */
final class ActionLine
{
    /** Argument names that read like words, in the order they are shown. */
    private const WORDS = ['summary', 'title', 'name', 'item', 'text', 'label', 'query', 'place', 'location', 'list', 'exercise', 'note', 'description', 'person'];
    private const WHEN  = ['start', 'time', 'when', 'at', 'date', 'due', 'remind_at', 'day'];

    /**
     * @param array<string, mixed> $args
     * @param (callable(string): ?string)|null $homeName resolves a Hue resource id to its saved name
     */
    public static function describe(string $name, array $args, bool $ok, ?callable $homeName = null): string
    {
        $line = self::line($name, $args, $homeName);

        return $ok ? $line : $line . ' (failed)';
    }

    /** @param array<string, mixed> $a */
    private static function line(string $name, array $a, ?callable $homeName): string
    {
        $q = static fn (string $s): string => '“' . $s . '”';
        $s = static fn (string $k): string => trim((string) ($a[$k] ?? ''));

        switch ($name) {
            case VoiceTools::GATEWAY:
                return 'Asked the full assistant' . ($s('request') !== '' ? ' · ' . $q(mb_substr($s('request'), 0, 80)) : '');
            case 'home_device_call':
                return self::home($a, $homeName);
            case 'insert_calendar_event':
                return 'Created event ' . $q($s('summary')) . self::when($a, ['start']);
            case 'update_calendar_event':
                $bits = [];
                if ($s('summary') !== '') {
                    $bits[] = $q($s('summary'));
                }
                $w = self::when($a, ['start']);

                return 'Updated event' . ($bits ? ' ' . $bits[0] : '') . ($w !== '' ? ' → ' . ltrim($w, ' ·') : '');
            case 'delete_calendar_event':
                return 'Deleted an event';
            case 'set_reminder':
                return 'Set reminder ' . $q($s('text') !== '' ? $s('text') : $s('title')) . self::when($a, ['when', 'time', 'remind_at']);
            case 'cancel_reminder':
                return 'Cancelled a reminder';
            case 'add_to_shopping_list':
                return 'Added ' . $q($s('item')) . ($s('list') !== '' ? ' to ' . $s('list') : ' to the shopping list');
            case 'check_off_item':
                return 'Checked off ' . $q($s('item'));
            case 'remove_from_shopping_list':
                return 'Removed ' . $q($s('item')) . ' from the list';
            case 'clear_checked_items':
                return 'Cleared the checked items';
            case 'get_current_weather':
            case 'get_weather_forecast':
                return 'Checked the weather' . ($s('location') !== '' ? ' · ' . $s('location') : '');
        }

        return self::generic($name, $a);
    }

    /** @param array<string, mixed> $a */
    private static function home(array $a, ?callable $homeName): string
    {
        $method = strtoupper(trim((string) ($a['method'] ?? 'GET')));
        $path   = (string) ($a['path'] ?? '');
        $body   = is_array($a['body'] ?? null) ? $a['body'] : [];
        if (strtolower((string) ($a['device'] ?? '')) === 'pico') {
            return 'Checked the home agent';
        }
        if ($method === 'GET') {
            return 'Looked up your ' . (preg_match('#/resource/(light|room|scene|zone|grouped_light|device)\b#', $path, $m) ? str_replace('grouped_light', 'room', $m[1]) . 's' : 'home devices');
        }
        $id   = preg_match('#/resource/[a-z_]+/([0-9a-f-]{8,})#i', $path, $m) ? $m[1] : '';
        $what = $id !== '' && $homeName !== null ? $homeName($id) : null;
        if (str_contains($path, '/scene/')) {
            return 'Activated scene' . ($what ? ' ' . $what : '');
        }
        $target = $what ?? (str_contains($path, '/grouped_light/') ? 'a room' : 'a light');
        if (isset($body['on']['on'])) {
            return ($body['on']['on'] ? 'Turned on ' : 'Turned off ') . $target;
        }
        if (isset($body['dimming']['brightness'])) {
            return 'Dimmed ' . $target . ' to ' . (int) $body['dimming']['brightness'] . '%';
        }

        return 'Changed ' . $target;
    }

    /**
     * @param array<string, mixed> $a
     * @param list<string> $keys
     */
    private static function when(array $a, array $keys): string
    {
        foreach ($keys as $k) {
            $v = trim((string) ($a[$k] ?? ''));
            if ($v !== '') {
                return ' · ' . self::time($v);
            }
        }

        return '';
    }

    /** "2026-10-22T10:00:00+02:00" → "Thu 22 Oct 10:00"; anything that is not a date is returned as it is. */
    public static function time(string $v): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return $v;
        }
        try {
            $d = new DateTimeImmutable($v);
        } catch (\Throwable $e) {
            return $v;
        }
        if (strlen($v) === 10) {
            return $d->format('D j M');
        }

        return $d->setTimezone(new DateTimeZone('Europe/Copenhagen'))->format('D j M H:i');
    }

    /** @param array<string, mixed> $a */
    private static function generic(string $name, array $a): string
    {
        $parts = explode('_', $name, 2);
        $verb  = $parts[0];
        $noun  = str_replace('_', ' ', $parts[1] ?? '');
        $label = match ($verb) {
            'get', 'list', 'show', 'search' => 'Checked ' . $noun,
            'add', 'create', 'insert', 'log', 'save', 'schedule', 'note', 'remember' => 'Added ' . $noun,
            'update', 'set', 'edit', 'rate', 'uncheck', 'check' => 'Updated ' . $noun,
            'delete', 'remove', 'cancel', 'forget' => 'Removed ' . $noun,
            default => ucfirst(str_replace('_', ' ', $name)),
        };
        $bits = [];
        foreach (array_merge(self::WORDS, self::WHEN) as $k) {
            $v = $a[$k] ?? null;
            if (!is_scalar($v) || is_bool($v) || trim((string) $v) === '') {
                continue;
            }
            $bits[] = mb_substr(self::time(trim((string) $v)), 0, 50);
            if (count($bits) >= 2) {
                break;
            }
        }

        return $label . ($bits !== [] ? ' · ' . implode(' · ', $bits) : '');
    }
}
