<?php

declare(strict_types=1);

namespace App\Notify;

/**
 * The catalogue of push notification types. This is the single place that makes
 * notifications modular: add an entry here and it automatically appears as a
 * toggle in the app's settings and can be sent with Notifier::notify($type).
 *
 * Each type has a stable key (stored in prefs), a user-facing label + blurb, and
 * a default on/off used when the user hasn't set a preference.
 */
final class NotificationTypes
{
    public const CHECKOUT_NUDGE = 'checkout_nudge';
    public const WEEKLY_SUMMARY = 'weekly_summary';
    public const WORK_LOG_NUDGE = 'work_log_nudge';
    public const CYCLE_UPCOMING = 'cycle_upcoming';
    public const REMINDER       = 'reminder';

    /** @var array<string, array{label:string, description:string, default:bool}> */
    private const CATALOGUE = [
        self::CHECKOUT_NUDGE => [
            'label'       => 'Forgot to clock out',
            'description' => 'A reminder if you are still clocked in late in the evening or after a long shift.',
            'default'     => true,
        ],
        self::WEEKLY_SUMMARY => [
            'label'       => 'Weekly work summary',
            'description' => 'A recap of last week\'s hours, sent Sunday evening.',
            'default'     => true,
        ],
        self::WORK_LOG_NUDGE => [
            'label'       => 'Log what you did',
            'description' => 'On a work day (from your Arbejde calendar), a mid-afternoon nudge to log what you got done if you haven\'t yet.',
            'default'     => true,
        ],
        // Sent by notify-cron when a logged period is due/overdue but not yet
        // registered. Off by default (sensitive) — the user opts in.
        self::CYCLE_UPCOMING => [
            'label'       => 'Period reminder',
            'description' => 'A reminder to log your period when it\'s expected but you haven\'t registered it yet.',
            'default'     => false,
        ],
        self::REMINDER => [
            'label'       => 'Reminders',
            'description' => 'One-off reminders you ask me to set ("remind me to … at …").',
            'default'     => true,
        ],
    ];

    /**
     * Which card to open when the user taps this notification. Maps a type to a card
     * key understood by api/card.php. Null → no card (just opens the app).
     */
    private const CARD_FOR = [
        self::CHECKOUT_NUDGE => 'work_hours', // today's hours (still clocked in?)
        self::WEEKLY_SUMMARY => 'work_week',  // last week's hours
        self::WORK_LOG_NUDGE => 'work_log',   // this week's work log
        self::CYCLE_UPCOMING => 'cycle',      // cycle status
    ];

    public static function exists(string $key): bool
    {
        return isset(self::CATALOGUE[$key]);
    }

    /**
     * Deep link to open when the notification is tapped: a fresh chat showing the
     * matching card (handled client-side via the `?card=` param). Null if none.
     */
    public static function deepLink(string $type): ?string
    {
        return isset(self::CARD_FOR[$type]) ? '/?card=' . self::CARD_FOR[$type] : null;
    }

    public static function defaultEnabled(string $key): bool
    {
        return self::CATALOGUE[$key]['default'] ?? false;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /**
     * The catalogue as a list, each entry with its key (for building the settings UI).
     *
     * @return array<int, array{key:string, label:string, description:string, default:bool}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $key => $meta) {
            $out[] = ['key' => $key] + $meta;
        }

        return $out;
    }
}
