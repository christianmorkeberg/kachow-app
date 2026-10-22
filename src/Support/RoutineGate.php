<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * The conditions of a routine, checked just before it runs. Pure: given the conditions, the local time, when it last ran
 * and what is known about who is home, says whether to go ahead — or why not (a short reason that is logged and shown).
 *
 * Conditions (all optional, all must hold):
 *   weekdays          list of ISO days 1=Mon..7=Sun
 *   between           ["HH:MM","HH:MM"] local; a window that wraps midnight ("22:00","06:00") is fine
 *   cooldown_minutes  not again within N minutes of the last run
 *   away              true = only when the user is NOT at a home place (false/absent: no condition)
 *   others_away       true = only when nobody else in the household is known to be home
 *   in_places         list of place ids: only while the user is inside ANY of them (a saved place or a region such as "east of Roskilde")
 *   out_places        list of place ids: only while the user is inside NONE of them
 *   from / until      "YYYY-MM-DD" or "YYYY-MM-DD HH:MM" local: only from / until then (a date alone means the whole day); after `until` the routine is over
 *   once              true = a one-off: it is finished after its first run
 * "Known" matters: with no recent position, `away`, `others_away`, `in_places` and `out_places` cannot be judged and do not block (dynamic, not
 * guessed — the routine still runs and the run says it could not tell).
 */
final class RoutineGate
{
    /**
     * @param array<string, mixed> $conditions
     * @param array{at_home?: ?bool, others_home?: ?bool, inside?: array<int, ?bool>} $context null = unknown; inside = place id → is the user in it
     * @return string|null null = allowed; otherwise why it is held back
     */
    public static function check(array $conditions, DateTimeImmutable $nowLocal, ?DateTimeImmutable $lastRunLocal, array $context = []): ?string
    {
        $from = self::bound((string) ($conditions['from'] ?? ''), false);
        if ($from !== null && $nowLocal < $from) {
            return 'not active yet (from ' . $conditions['from'] . ')';
        }
        if (self::expired($conditions, $nowLocal)) {
            return 'it has expired (' . $conditions['until'] . ')';
        }
        $days = $conditions['weekdays'] ?? null;
        if (is_array($days) && $days !== [] && !in_array((int) $nowLocal->format('N'), array_map('intval', $days), true)) {
            return 'not on this day of the week';
        }
        $between = $conditions['between'] ?? null;
        if (is_array($between) && count($between) === 2 && !self::inWindow((string) $between[0], (string) $between[1], $nowLocal)) {
            return 'outside its time window (' . $between[0] . '–' . $between[1] . ')';
        }
        $cool = (int) ($conditions['cooldown_minutes'] ?? 0);
        if ($cool > 0 && $lastRunLocal !== null && $nowLocal->getTimestamp() - $lastRunLocal->getTimestamp() < $cool * 60) {
            return 'it ran less than ' . $cool . ' minutes ago';
        }
        if (!empty($conditions['away']) && ($context['at_home'] ?? null) === true) {
            return 'you are at home';
        }
        if (!empty($conditions['others_away']) && ($context['others_home'] ?? null) === true) {
            return 'someone else is still at home';
        }
        $inside = $context['inside'] ?? [];
        $need   = array_map('intval', (array) ($conditions['in_places'] ?? []));
        if ($need !== []) {
            $known = array_filter(array_map(static fn (int $id): ?bool => $inside[$id] ?? null, $need), static fn ($v): bool => $v !== null);
            if (count($known) === count($need) && !in_array(true, $known, true)) {
                return 'you are not in the place it needs';
            }
        }
        foreach (array_map('intval', (array) ($conditions['out_places'] ?? [])) as $id) {
            if (($inside[$id] ?? null) === true) {
                return 'you are in a place it avoids';
            }
        }

        return null;
    }

    /** Whether a routine's `until` has passed (a date alone means the end of that day). */
    public static function expired(array $conditions, DateTimeImmutable $nowLocal): bool
    {
        $until = self::bound((string) ($conditions['until'] ?? ''), true);

        return $until !== null && $nowLocal > $until;
    }

    /** "YYYY-MM-DD" (start / end of that day) or "YYYY-MM-DD HH:MM" as a local time, or null if unreadable. */
    private static function bound(string $s, bool $endOfDay): ?DateTimeImmutable
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $tz = new \DateTimeZone('Europe/Copenhagen');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, $tz);

            return $d === false ? null : ($endOfDay ? $d->setTime(23, 59, 59) : $d);
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}):(\d{2})$/', $s, $m) === 1) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], $tz);

            return $d === false ? null : $d->setTime((int) $m[2], (int) $m[3], $endOfDay ? 59 : 0);
        }

        return null;
    }

    public static function inWindow(string $from, string $to, DateTimeImmutable $nowLocal): bool
    {
        $m = static function (string $hhmm): ?int {
            return preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $x) === 1 ? ((int) $x[1]) * 60 + (int) $x[2] : null;
        };
        $a = $m($from);
        $b = $m($to);
        if ($a === null || $b === null) {
            return true;   // an unreadable window never blocks
        }
        $now = ((int) $nowLocal->format('G')) * 60 + (int) $nowLocal->format('i');

        return $a <= $b ? ($now >= $a && $now < $b) : ($now >= $a || $now < $b);
    }

    /**
     * Cleans a conditions array from a model: keeps the known keys, coerces types, drops the rest.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function normalize(array $in): array
    {
        $out = [];
        if (isset($in['weekdays']) && is_array($in['weekdays'])) {
            $d = array_values(array_unique(array_filter(array_map('intval', $in['weekdays']), static fn (int $x): bool => $x >= 1 && $x <= 7)));
            sort($d);
            if ($d !== []) {
                $out['weekdays'] = $d;
            }
        }
        if (isset($in['between']) && is_array($in['between']) && count($in['between']) === 2) {
            $ok = true;
            foreach ($in['between'] as $t) {
                $ok = $ok && preg_match('/^\d{1,2}:\d{2}$/', trim((string) $t)) === 1;
            }
            if ($ok) {
                $out['between'] = [trim((string) $in['between'][0]), trim((string) $in['between'][1])];
            }
        }
        if (isset($in['cooldown_minutes']) && (int) $in['cooldown_minutes'] > 0) {
            $out['cooldown_minutes'] = min(1440, (int) $in['cooldown_minutes']);
        }
        foreach (['from', 'until'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '' && self::bound($v, $k === 'until') !== null) {
                $out[$k] = $v;
            }
        }
        foreach (['in_places', 'out_places'] as $k) {
            if (isset($in[$k]) && is_array($in[$k])) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $in[$k]), static fn (int $x): bool => $x > 0)));
                if ($ids !== []) {
                    $out[$k] = $ids;
                }
            }
        }
        if (!empty($in['once']) && filter_var($in['once'], FILTER_VALIDATE_BOOLEAN)) {
            $out['once'] = true;
        }
        foreach (['away', 'others_away'] as $k) {
            if (!empty($in[$k]) && filter_var($in[$k], FILTER_VALIDATE_BOOLEAN)) {
                $out[$k] = true;
            }
        }

        return $out;
    }

    /** One line for the card / the model: "weekdays · 07:00–22:00 · not again within 30 min · only when I'm away · only if nobody else is home". */
    public static function describe(array $c, array $placeNames = []): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $bits  = [];
        if (!empty($c['weekdays'])) {
            $bits[] = implode(', ', array_map(static fn ($d): string => $names[(int) $d] ?? (string) $d, $c['weekdays']));
        }
        if (!empty($c['between'])) {
            $bits[] = $c['between'][0] . '–' . $c['between'][1];
        }
        if (!empty($c['cooldown_minutes'])) {
            $bits[] = 'not again within ' . $c['cooldown_minutes'] . ' min';
        }
        if (!empty($c['away'])) {
            $bits[] = 'only when I\'m away from home';
        }
        if (!empty($c['others_away'])) {
            $bits[] = 'only if nobody else is home';
        }
        $names = static fn (array $ids): string => implode(' / ', array_map(static fn ($id): string => $placeNames[(int) $id] ?? ('place ' . (int) $id), $ids));
        if (!empty($c['in_places'])) {
            $bits[] = 'only when I\'m in ' . $names($c['in_places']);
        }
        if (!empty($c['out_places'])) {
            $bits[] = 'not when I\'m in ' . $names($c['out_places']);
        }
        if (!empty($c['from'])) {
            $bits[] = 'from ' . $c['from'];
        }
        if (!empty($c['until'])) {
            $bits[] = 'until ' . $c['until'];
        }
        if (!empty($c['once'])) {
            $bits[] = 'once only';
        }

        return implode(' · ', $bits);
    }
}
