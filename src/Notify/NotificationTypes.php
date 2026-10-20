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
    public const MOMS_DEADLINE  = 'moms_deadline';
    public const REMINDER       = 'reminder';
    public const WFH_PROMPT     = 'wfh_prompt';
    public const TRACKING_SILENT = 'tracking_silent';
    public const SCHEDULED_TASK  = 'scheduled_task';
    public const WORK_CLOCK_GAP  = 'work_clock_gap';
    public const FEEDBACK_REPORT = 'feedback_report';
    public const IMPORTANT_EMAIL = 'important_email';
    public const ROUTINE_ASK = 'routine_ask';

    /** @var array<string, array{label:string, description:string, default:bool, admin?:bool}> */
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
        // Sent by notify-cron in the days before a quarterly moms filing deadline, when
        // there's activity to file. Only fires if you actually keep books, so it stays
        // quiet for non-business users.
        self::MOMS_DEADLINE => [
            'label'       => 'Moms deadline',
            'description' => 'A reminder in the days before a quarterly moms (VAT) filing deadline, if you have bookkeeping activity to file.',
            'default'     => true,
        ],
        self::REMINDER => [
            'label'       => 'Reminders',
            'description' => 'One-off reminders you ask me to set ("remind me to … at …").',
            'default'     => true,
        ],
        // Sent by notify-cron around 09:00 on weekdays when location tracking shows you at a
        // home place, not yet at a workplace and not clocked in. Needs a "home" place.
        self::WFH_PROMPT => [
            'label'       => 'Working from home?',
            'description' => 'On a weekday morning when you\'re scheduled to work (a work-calendar event) but still at home (location tracking), asks whether you\'re working from home so the clock can start.',
            'default'     => true,
        ],
        // Sent by notify-cron when no location point has arrived for hours of daytime — i.e.
        // OwnTracks has quietly stopped (force-quit, suspended, Low Power Mode, no data abroad).
        self::TRACKING_SILENT => [
            'label'       => 'Tracking stopped',
            'description' => 'A heads-up when no position has arrived for several hours during the day, so a '
                . 'stopped OwnTracks is caught the same day instead of leaving a hole in your timeline.',
            'default'     => true,
        ],
        // Sent by a scheduled task's own run (bin/scheduled-tasks-cron.php), via the
        // notify_me tool, only when that run actually found something worth reporting.
        self::SCHEDULED_TASK => [
            'label'       => 'Scheduled task found something',
            'description' => 'When a recurring task you set up (schedule_task) actually finds something '
                . 'worth telling you — silent otherwise.',
            'default'     => true,
        ],
        // Sent by notify-cron the morning after, when the location work clock skipped a work
        // visit because the phone stopped reporting around it (arrival or leaving unknown).
        self::WORK_CLOCK_GAP => [
            'label'       => 'Hours to fill in',
            'description' => 'With the location work clock on: a morning heads-up when a work visit yesterday '
                . 'could not be clocked because of a tracking gap, so you can add the hours yourself.',
            'default'     => true,
        ],
        // Sent by notify-cron: new unread mail that a model judges to need attention. Off by default — it reads
        // the user's mailbox and sends subjects to the model — so the user opts in.
        self::IMPORTANT_EMAIL => [
            'label'       => 'Important unread email',
            'description' => 'A heads-up when new unread mail looks like it needs you (a person waiting for a reply, a payment or '
                . 'deadline, a booking). Newsletters and receipts are ignored. Mail subjects and previews are read by the assistant to decide.',
            'default'     => false,
        ],
        // Sent by the routine runner when a routine set to "ask first" is triggered automatically (leaving a place …):
        // the question waits on the routines card until answered.
        self::ROUTINE_ASK => [
            'label'       => 'Routine asks first',
            'description' => 'When a routine you set to "ask first" is triggered (for example by leaving home): a question to run it or skip it.',
            'default'     => true,
        ],
        // Sent by api/report.php when a user flags a message ("report to developer"). Replaces the
        // e-mail to the developer; only admins are ever sent it, so only admins see the toggle.
        self::FEEDBACK_REPORT => [
            'label'       => 'New feedback report',
            'description' => 'Developer: when someone flags a message from the chat. Tap it to open the open reports.',
            'default'     => true,
            'admin'       => true,
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
        self::MOMS_DEADLINE  => 'moms',       // the quarter awaiting filing
        self::WORK_CLOCK_GAP => 'work_yesterday', // yesterday's hours, to fill in the skipped visit
        self::FEEDBACK_REPORT => 'feedback',  // the open reports (admin)
        self::ROUTINE_ASK    => 'routines',   // the routines card, with the question
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

    /** Types only the developer/admin is offered (and sent). */
    public static function isAdminOnly(string $key): bool
    {
        return (self::CATALOGUE[$key]['admin'] ?? false) === true;
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
