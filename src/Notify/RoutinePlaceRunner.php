<?php

declare(strict_types=1);

namespace App\Notify;

use App\Data\Places;
use App\Data\Routines;
use App\Support\BackgroundRun;
use App\Support\PlaceTransitions;

/**
 * Starts location routines ("when I leave Home …") as positions arrive — called from api/owntracks.php with the points it
 * just stored, like PlaceReminderRunner. A user with no place routines costs one query. The watched places are matched by the
 * user's OWN shapes (circle or polygon, by id or by type), "inside" meaning inside ANY of them; the state (inside or not, and
 * how many accurate points disagree) lives in routine_place_state, so nothing is hard-coded about distances beyond the
 * PlaceTransitions rules.
 *
 * It only REQUESTS the run: the routine's conditions, the "ask first" question and the run itself happen in
 * bin/scheduled-tasks-cron.php, which is started in the background here (the per-minute cron is the fallback).
 */
final class RoutinePlaceRunner
{
    /** Positions older than this still update the state (a phone re-sends queued fixes) but start nothing. */
    public const FRESH_SECONDS = 30 * 60;

    /** @var (callable(): void)|null */
    private $kick;

    /** @param (callable(): void)|null $kick starts the runner in the background; default: bin/scheduled-tasks-cron.php --requested-only */
    public function __construct(private Routines $routines, private Places $places, ?callable $kick = null, private ?int $now = null)
    {
        $this->kick = $kick;
    }

    /**
     * @param list<array{ts:int, lat:float, lon:float, acc:?int, vel:?int}> $points oldest first
     * @return list<int> ids of the routines a run was requested for
     */
    public function onPoints(int $userId, array $points): array
    {
        if ($points === []) {
            return [];
        }
        $routines = $this->routines->active($userId, 'place');
        if ($routines === []) {
            return [];
        }
        $places = $this->places->list($userId);
        $names  = [];
        foreach ($places as $p) {
            $names[(int) $p['id']] = (string) $p['name'];
        }
        $now       = $this->now ?? time();
        $requested = [];

        foreach ($routines as $r) {
            $cfg     = $r['trigger'];
            $targets = array_values(array_filter($places, static fn (array $p): bool => in_array((int) $p['id'], $cfg['place_ids'] ?? [], true)
                || in_array((string) $p['type'], $cfg['place_types'] ?? [], true)));
            if ($targets === []) {
                continue;   // the place was deleted: nothing to watch
            }
            $state = $this->routines->placeState($r['id'], 0);   // place_id 0 = "inside ANY of the watched places"
            foreach ($points as $pt) {
                $step = PlaceTransitions::step($state, $targets, $pt);
                if ($step['state'] !== null) {
                    $state = $step['state'];
                    $this->routines->savePlaceState($r['id'], 0, $state['inside'], $state['streak']);
                }
                if ($step['event'] !== null && $step['event'] === ($cfg['event'] ?? 'leave') && $now - $pt['ts'] <= self::FRESH_SECONDS) {
                    $what = implode(' or ', array_map(static fn (array $p): string => (string) $p['name'], $targets));
                    $text = ($step['event'] === 'leave' ? 'you left ' : 'you arrived at ') . $what;
                    if ($this->routines->requestRun($userId, $r['id'], $text)) {
                        $requested[] = $r['id'];
                    }
                }
            }
        }
        if ($requested !== []) {
            $kick = $this->kick ?? static function (): void {
                BackgroundRun::kick(dirname(__DIR__, 2) . '/bin/scheduled-tasks-cron.php', ['--requested-only']);
            };
            $kick();
        }

        return array_values(array_unique($requested));
    }
}
