<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Sunrise and sunset in Denmark, from PHP's own astronomy (date_sun_info — no web call, no key). Pure. The coordinates are Copenhagen's: Denmark is so small that the
 * sunset differs by minutes between its ends, which is fine for "turn the light on when it gets dark".
 */
final class Sun
{
    public const LAT = 55.68;
    public const LON = 12.57;

    /** @return array{sunrise:DateTimeImmutable, sunset:DateTimeImmutable}|null of the LOCAL date of $nowLocal (null where the sun does not rise or set) */
    public static function times(DateTimeImmutable $nowLocal): ?array
    {
        $tz   = new DateTimeZone('Europe/Copenhagen');
        $noon = $nowLocal->setTimezone($tz)->setTime(12, 0);
        $info = date_sun_info($noon->getTimestamp(), self::LAT, self::LON);
        if (!is_array($info) || !is_int($info['sunrise'] ?? null) || !is_int($info['sunset'] ?? null)) {
            return null;
        }

        return ['sunrise' => (new DateTimeImmutable('@' . $info['sunrise']))->setTimezone($tz), 'sunset' => (new DateTimeImmutable('@' . $info['sunset']))->setTimezone($tz)];
    }

    /** Whether it is dark at $nowLocal: before sunrise or from sunset. $offsetMin moves both (positive = later, e.g. 30 = half an hour after sunset). Null = cannot tell. */
    public static function isDark(DateTimeImmutable $nowLocal, int $offsetMin = 0): ?bool
    {
        $t = self::times($nowLocal);
        if ($t === null) {
            return null;
        }
        $shift = $offsetMin * 60;
        $ts    = $nowLocal->getTimestamp();

        return $ts < $t['sunrise']->getTimestamp() + $shift || $ts >= $t['sunset']->getTimestamp() + $shift;
    }
}
