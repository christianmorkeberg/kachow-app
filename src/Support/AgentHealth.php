<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Is the home agent (the Pico) still checking in? It long-polls every ≤25 s, so a few minutes of silence is already unusual; this decides when
 * to TELL the user. Pure, so it is unit-testable (`bin/agent-health-test.php`); notify-cron supplies the time and the token's last-use.
 */
final class AgentHealth
{
    /** Silent this long (seconds) before we say anything: past a Wi-Fi blip or a router reboot, short of "it is broken". */
    public const OFFLINE_AFTER_S = 1200;

    /** After this long the user knows — stop (the alert is once per outage anyway). */
    public const GIVE_UP_AFTER_S = 14 * 86400;

    /** Alert only between these local hours: a push at 03:00 helps nobody (it is sent at the next waking slot). */
    public const WAKE_FROM = 7;
    public const WAKE_TO   = 22;

    /**
     * @param int|null $silentSeconds seconds since the agent last contacted the server; null = it never has (not set up — nothing to say)
     */
    public static function shouldAlert(?int $silentSeconds, DateTimeImmutable $nowLocal): bool
    {
        if ($silentSeconds === null || $silentSeconds < self::OFFLINE_AFTER_S || $silentSeconds > self::GIVE_UP_AFTER_S) {
            return false;
        }
        $hour = (int) $nowLocal->format('G');

        return $hour >= self::WAKE_FROM && $hour < self::WAKE_TO;
    }
}
