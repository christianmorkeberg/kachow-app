<?php

declare(strict_types=1);

/**
 * Scheduled notification runner — run every 30 min by cron (so the 16:30 work-log
 * nudge can fire; all jobs are idempotent so running twice an hour is safe):
 *
 *     0,30 * * * * php /home/kachowdk/assistant-app/bin/notify-cron.php >/dev/null 2>&1
 *
 * It decides what to do using Europe/Copenhagen local time (so one hourly entry
 * works regardless of the server's timezone or DST):
 *   - Checkout nudge: for anyone still clocked in after 19:00 (day session) or
 *     past a 10h shift — once per session.
 *   - Weekly summary: Sunday evening, last week's hours per user.
 *
 * Safe to run every hour; both jobs are de-duplicated (work_events.nudged_at and
 * the notification_log ledger), and it exits quietly if push isn't configured.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Auth\GoogleOAuth;
use App\Data\Calendar;
use App\Data\CycleTracker;
use App\Data\NotificationLog;
use App\Data\PushSubscriptions;
use App\Data\Users;
use App\Data\UserSettings;
use App\Data\WorkEvents;
use App\Data\WorkLog;
use App\Notify\NotificationTypes;
use App\Notify\Notifier;
use App\Notify\WebPush;

if (!WebPush::isConfigured()) {
    fwrite(STDERR, "notify-cron: VAPID not configured; nothing to do.\n");
    exit(0);
}

// Tunables.
const EVENING_HOUR      = 19;   // "still clocked in this evening"
const EVENING_MIN_MIN   = 120;  // …but only after being in ≥2h (avoid nudging a fresh evening start)
const LONG_SHIFT_MIN    = 600;  // 10h
const WEEKLY_HOUR       = 19;   // Sunday from 19:00

$tz       = new DateTimeZone(WorkEvents::LOCAL_TZ);
$utc      = new DateTimeZone('UTC');
$nowLocal = new DateTimeImmutable('now', $tz);

$notifier = Notifier::fromEnv();
$work     = new WorkEvents();
$log      = new NotificationLog();

// Only users with a subscribed device are worth processing.
$subscribed = array_flip((new PushSubscriptions())->subscribedUserIds());

// ---------- Checkout nudge ----------
try {
    foreach ($work->openSessionsForNudge() as $s) {
        $uid = $s['user_id'];
        if (!isset($subscribed[$uid])) {
            continue;
        }

        $inUtc    = new DateTimeImmutable($s['occurred_at'], $utc);
        $inLocal  = $inUtc->setTimezone($tz);
        $durMin   = (int) round(($nowLocal->getTimestamp() - $inUtc->getTimestamp()) / 60);
        $sameDay  = $inLocal->format('Y-m-d') === $nowLocal->format('Y-m-d');

        $longShift = $durMin >= LONG_SHIFT_MIN;
        $evening   = (int) $nowLocal->format('G') >= EVENING_HOUR && $sameDay && $durMin >= EVENING_MIN_MIN;
        if (!$longShift && !$evening) {
            continue;
        }

        // Claim first so the reminder fires exactly once, even on overlapping runs.
        if (!$work->claimNudge($s['id'])) {
            continue;
        }

        $place = trim((string) ($s['location'] ?? ''));
        $where = $place !== '' ? ' at ' . $place : '';
        $notifier->notify(
            $uid,
            NotificationTypes::CHECKOUT_NUDGE,
            'Still clocked in',
            "You've been clocked in{$where} since {$inLocal->format('H:i')} ("
                . fmtDur($durMin) . '). Did you forget to clock out?'
        );
    }
} catch (\Throwable $e) {
    error_log('notify-cron nudge: ' . $e->getMessage());
}

// ---------- Weekly summary (Sunday evening) ----------
try {
    if ((int) $nowLocal->format('N') === 7 && (int) $nowLocal->format('G') >= WEEKLY_HOUR) {
        $periodKey = $nowLocal->format('o-\WW'); // ISO year-week, unique per week

        foreach (array_keys($subscribed) as $uid) {
            $sum = $work->summary($uid, 'lastweek');
            if ($sum['total_minutes'] <= 0) {
                continue; // nothing to report
            }
            if (!$log->claim($uid, NotificationTypes::WEEKLY_SUMMARY, $periodKey)) {
                continue; // already sent this week
            }

            $body = 'You worked ' . $sum['total_label'] . ' last week';
            if (count($sum['places']) > 1) {
                $parts = array_map(
                    static fn (array $p): string => ($p['place'] !== '' ? $p['place'] : '—') . ' ' . $p['total'],
                    $sum['places']
                );
                $body .= ' (' . implode(' · ', $parts) . ')';
            }
            $body .= '.';

            $notifier->notify($uid, NotificationTypes::WEEKLY_SUMMARY, 'Last week at work', $body);
        }
    }
} catch (\Throwable $e) {
    error_log('notify-cron weekly: ' . $e->getMessage());
}

// ---------- Work-log nudge (mid-afternoon on work days) ----------
// On a day the user has an "Arbejde" calendar event, if they haven't logged what
// they did yet, nudge them. Fires from 16:30 local onward (needs a cron run at :30 —
// see the crontab note at the top), once per day per user via the notification ledger.
const LOG_NUDGE_FROM_MIN = 16 * 60 + 30; // 16:30 local
try {
    $hour    = (int) $nowLocal->format('G');
    $minsNow = $hour * 60 + (int) $nowLocal->format('i');
    if ($minsNow >= LOG_NUDGE_FROM_MIN && $hour < 20) {
        $today    = $nowLocal->format('Y-m-d');
        $calendar = new Calendar(GoogleOAuth::fromEnv(new Users()));
        $worklog  = new WorkLog();
        $settings = new UserSettings();

        foreach (array_keys($subscribed) as $uid) {
            $calName = $settings->get($uid, 'work_calendar') ?? WorkLog::WORK_CALENDAR;
            try {
                $events = $calendar->eventsForDay($uid, $today, $calName);
            } catch (\Throwable $e) {
                continue; // no Google connection / no such calendar
            }

            $jobs = [];
            foreach ($events as $e) {
                $j = WorkLog::jobFromTitle((string) ($e['summary'] ?? ''));
                if ($j !== '' && !in_array($j, $jobs, true)) {
                    $jobs[] = $j;
                }
            }
            if ($jobs === []) {
                continue; // no work today
            }

            $pending = array_values(array_diff($jobs, $worklog->loggedJobsForDate($uid, $today)));
            if ($pending === []) {
                continue; // already logged everything
            }

            // Claim only when actually nudging, so a transient failure can retry next hour.
            if (!$log->claim($uid, NotificationTypes::WORK_LOG_NUDGE, $today)) {
                continue;
            }

            $where = 'at ' . (count($pending) === 1 ? $pending[0] : implode(' and ', $pending));
            $notifier->notify(
                $uid,
                NotificationTypes::WORK_LOG_NUDGE,
                'What did you get done?',
                "You're {$where} today — tap to log what you worked on."
            );
        }
    }
} catch (\Throwable $e) {
    error_log('notify-cron worklog: ' . $e->getMessage());
}

// ---------- Cycle: "register your period" reminder ----------
// Once at least one period is logged, when the predicted next start has arrived (up
// to a few days overdue) and no new period has been registered, remind the user to
// log it. Fires from CYCLE_REMIND_HOUR (hourly cron ⇒ ~10:00), once per day per user
// via the ledger, and only if they enabled the "Period reminder" toggle (default off).
const CYCLE_REMIND_HOUR = 10;
try {
    $hour = (int) $nowLocal->format('G');
    if ($hour >= CYCLE_REMIND_HOUR && $hour < 13) {
        $today = $nowLocal->format('Y-m-d');
        $cycle = new CycleTracker();

        foreach (array_keys($subscribed) as $uid) {
            $due = $cycle->reminderDue($uid);
            if ($due === null) {
                continue;
            }
            // Claim only when actually reminding, so a transient failure can retry next hour.
            if (!$log->claim($uid, NotificationTypes::CYCLE_UPCOMING, $today)) {
                continue;
            }

            $late = (int) $due['days_late'];
            $body = $late === 0
                ? 'Your period is expected today — tap to log it when it starts.'
                : 'Your period was expected ' . $late . ' day' . ($late === 1 ? '' : 's')
                    . ' ago — tap to log it if it has started.';
            $notifier->notify($uid, NotificationTypes::CYCLE_UPCOMING, 'Period check-in', $body);
        }
    }
} catch (\Throwable $e) {
    error_log('notify-cron cycle: ' . $e->getMessage());
}

exit(0);

function fmtDur(int $minutes): string
{
    $minutes = max(0, $minutes);
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h === 0) {
        return $m . 'm';
    }

    return $m === 0 ? $h . 'h' : $h . 'h ' . $m . 'm';
}
