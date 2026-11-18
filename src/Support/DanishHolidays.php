<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Danish public holidays for a year, computed (Easter-based ones included) — SUGGESTIONS only: nothing is marked until the user says so.
 * `common` = days many employers give off but that are not public holidays (Constitution Day, Christmas Eve, the day after Ascension Day, New Year's Eve).
 */
final class DanishHolidays
{
    /** @return list<array{date:string, name:string, common:bool}> sorted by date */
    public static function forYear(int $year): array
    {
        $easter = (new DateTimeImmutable($year . '-03-21'))->modify('+' . easter_days($year) . ' days');
        $d = static fn (DateTimeImmutable $x): string => $x->format('Y-m-d');
        $out = [
            ['date' => $year . '-01-01', 'name' => 'New Year\'s Day', 'common' => false],
            ['date' => $d($easter->modify('-3 days')), 'name' => 'Maundy Thursday', 'common' => false],
            ['date' => $d($easter->modify('-2 days')), 'name' => 'Good Friday', 'common' => false],
            ['date' => $d($easter), 'name' => 'Easter Sunday', 'common' => false],
            ['date' => $d($easter->modify('+1 day')), 'name' => 'Easter Monday', 'common' => false],
            ['date' => $d($easter->modify('+39 days')), 'name' => 'Ascension Day', 'common' => false],
            ['date' => $d($easter->modify('+49 days')), 'name' => 'Whit Sunday', 'common' => false],
            ['date' => $d($easter->modify('+50 days')), 'name' => 'Whit Monday', 'common' => false],
            ['date' => $year . '-12-25', 'name' => 'Christmas Day', 'common' => false],
            ['date' => $year . '-12-26', 'name' => 'Boxing Day', 'common' => false],
            ['date' => $year . '-06-05', 'name' => 'Constitution Day (often off)', 'common' => true],
            ['date' => $d($easter->modify('+40 days')), 'name' => 'Day after Ascension Day (often off)', 'common' => true],
            ['date' => $year . '-12-24', 'name' => 'Christmas Eve (often off)', 'common' => true],
            ['date' => $year . '-12-31', 'name' => 'New Year\'s Eve (often off)', 'common' => true],
        ];
        if ($year < 2024) {
            $out[] = ['date' => $d($easter->modify('+26 days')), 'name' => 'Great Prayer Day', 'common' => false];   // abolished from 2024
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $out;
    }
}
