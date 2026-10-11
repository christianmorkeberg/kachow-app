<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Is location tracking still reporting? OwnTracks can stop quietly — the app force-quit from
 * the switcher, iOS suspending it, Low Power Mode, or no data abroad — and the only sign is
 * that points stop arriving. Until this existed, one such stop went unnoticed for six days.
 *
 * Pure rules, so they are unit-testable (`bin/location-gap-test.php`); notify-cron supplies
 * the time, the threshold and the last point, and sends the push.
 */
final class TrackingHealth
{
    /** Hours of daytime silence before we say something (user setting location_silent_alert_hours). */
    public const DEFAULT_SILENT_HOURS = 6;
    public const MIN_SILENT_HOURS     = 2;
    public const MAX_SILENT_HOURS     = 48;

    /** Alert only between these local hours: a nudge at 03:00 helps nobody. */
    public const WAKE_FROM = 8;
    public const WAKE_TO   = 22;

    /** After this long the silence is no longer news — the user knows, so stop nagging. */
    public const MAX_SILENT_DAYS = 7;

    /**
     * Should we tell the user that tracking has stopped?
     *
     * Only silence during WAKING hours counts. A phone on a charger overnight publishes nothing
     * when `locatorDisplacement` filters out standing still, so counting the night would alert
     * every single morning. Measuring from the start of the waking window instead means a truly
     * dead tracker is reported in the afternoon, and a quiet night never is.
     *
     * @param int|null $lastPointTs UTC timestamp of the newest point, or null if there is none
     *                              (tracking was never set up — nothing to complain about)
     */
    public static function shouldAlert(
        ?int $lastPointTs,
        DateTimeImmutable $nowLocal,
        int $silentHours = self::DEFAULT_SILENT_HOURS,
        int $maxSilentDays = self::MAX_SILENT_DAYS,
    ): bool {
        if ($lastPointTs === null) {
            return false;
        }
        $hour = (int) $nowLocal->format('G');
        if ($hour < self::WAKE_FROM || $hour >= self::WAKE_TO) {
            return false;
        }
        $now = $nowLocal->getTimestamp();
        if ($now - $lastPointTs > $maxSilentDays * 86400) {
            return false;
        }
        $since = max($lastPointTs, $nowLocal->setTime(self::WAKE_FROM, 0)->getTimestamp());

        return $now - $since >= max(1, $silentHours) * 3600;
    }

    /** The setting value clamped to a sane range. */
    public static function silentHours(?string $setting): int
    {
        $h = $setting === null || trim($setting) === '' ? self::DEFAULT_SILENT_HOURS : (int) round((float) $setting);

        return max(self::MIN_SILENT_HOURS, min(self::MAX_SILENT_HOURS, $h));
    }
}
