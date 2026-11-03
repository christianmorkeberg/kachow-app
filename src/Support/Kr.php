<?php

declare(strict_types=1);

namespace App\Support;

/** Danish number/date formatting for the bookkeeping exports (decimal comma, no thousands separator so sums still parse). */
final class Kr
{
    public static function amount(float $x): string
    {
        return number_format(round($x, 2), 2, ',', '');
    }

    /** 2026-03-09 → 09-03-2026 */
    public static function date(?string $iso): string
    {
        if ($iso === null || $iso === '' || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) !== 1) {
            return (string) $iso;
        }

        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
}
