<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What the location clock should change for one day: compares the work sessions derived from
 * location with the punches it wrote earlier (source 'location') and returns the punches to add
 * and remove. Pure, so the rules that decide billed hours are unit-tested on plain arrays.
 *
 *  - Each certain session wants an "in" at its start and, unless still ongoing, an "out" at its end.
 *  - A session touching a no-data gap (within GAP_MARGIN_S of either end, or overlapping it) is
 *    UNCERTAIN: its arrival or leaving time is unknown, so nothing is written for it (the user is
 *    asked to fill it in instead) — Christian's choice, 2026-10-17.
 *  - An existing punch that matches a wanted one (same kind and place, within MATCH_S) is kept,
 *    so a re-run changes nothing; anything else it wrote before is removed (a provisional
 *    clock-out that a same-day return has since bridged, a visit that turned out uncertain).
 */
final class LocationClockPlan
{
    public const GAP_MARGIN_S = 600;
    public const MATCH_S      = 90;

    /**
     * @param list<array{place:?string, in:int, out:int, ongoing:bool}> $sessions
     * @param list<array{start:int, end:int}> $gaps
     * @param list<array{id:int, kind:string, ts:int, location:?string}> $existing  punches with source 'location'
     * @return array{insert: list<array{kind:string, ts:int, location:?string}>, delete: list<int>, uncertain: list<array<string, mixed>>}
     */
    public static function plan(array $sessions, array $gaps, array $existing): array
    {
        $want = [];
        $uncertain = [];
        foreach ($sessions as $s) {
            if (self::touchesGap($s, $gaps)) {
                $uncertain[] = $s;
                continue;
            }
            $want[] = ['kind' => 'in', 'ts' => (int) $s['in'], 'location' => $s['place'] ?? null];
            if (empty($s['ongoing'])) {
                $want[] = ['kind' => 'out', 'ts' => (int) $s['out'], 'location' => $s['place'] ?? null];
            }
        }

        $pool   = $existing;
        $insert = [];
        foreach ($want as $w) {
            $hit = null;
            foreach ($pool as $k => $e) {
                if ($e['kind'] === $w['kind'] && self::key($e['location']) === self::key($w['location'])
                    && abs($e['ts'] - $w['ts']) <= self::MATCH_S) {
                    $hit = $k;
                    break;
                }
            }
            if ($hit === null) {
                $insert[] = $w;
            } else {
                unset($pool[$hit]);
            }
        }

        return [
            'insert'    => $insert,
            'delete'    => array_values(array_map(static fn (array $e): int => $e['id'], $pool)),
            'uncertain' => $uncertain,
        ];
    }

    private static function touchesGap(array $s, array $gaps): bool
    {
        $from = (int) $s['in'] - self::GAP_MARGIN_S;
        $to   = (int) $s['out'] + self::GAP_MARGIN_S;
        foreach ($gaps as $g) {
            if ((int) $g['start'] <= $to && (int) $g['end'] >= $from) {
                return true;
            }
        }

        return false;
    }

    private static function key(?string $s): string
    {
        return mb_strtolower(mb_substr(trim((string) $s), 0, 64));   // work_events.location holds 64 chars
    }
}
