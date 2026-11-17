<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The one place for "what time is it for the user". The server (PHP and the DB) runs in UTC; the user lives in Europe/Copenhagen. Anything that is SHOWN to the
 * model or the user, and any "today" default, goes through here, so the model is never handed a UTC time to convert by hand (it adds the wrong offset: report #83).
 */
final class LocalTime
{
    public const TZ = 'Europe/Copenhagen';

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::TZ);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone());
    }

    /** Today's local date, 'Y-m-d'. */
    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function year(): int
    {
        return (int) self::now()->format('Y');
    }

    /** A stored UTC timestamp as a local label, e.g. "Tue 17 Nov 16:41". null/empty stay null. */
    public static function label(?string $utc, string $format = 'D j M H:i'): ?string
    {
        if ($utc === null || trim($utc) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(self::zone())->format($format);
        } catch (\Exception) {
            return $utc;
        }
    }

    /**
     * A time the user (or the model, quoting them) gave as LOCAL wall-clock → a UTC 'Y-m-d H:i:s' string for storing, or null when it can't be read.
     * A trailing "Z" / "+01:00" the model added is ignored: the digits are what the user said.
     */
    public static function toUtc(string $local): ?string
    {
        $s = preg_replace('/\s*(Z|UTC|[+-]\d{2}:?\d{2})\s*$/i', '', trim($local)) ?? trim($local);
        try {
            return (new DateTimeImmutable($s, self::zone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
