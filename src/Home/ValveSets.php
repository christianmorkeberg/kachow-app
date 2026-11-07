<?php

declare(strict_types=1);

namespace App\Home;

use App\Data\HomeState;

/**
 * What became of the last target sent to each radiator valve, for the Home card: sending (sent, the valve has not reported it yet), confirmed (it did),
 * failed (the deadline passed without confirmation). Kept in the home-state cache (kind 'valve_sets'); PendingSets writes it as sets are sent, confirmed
 * and given up on, so every path that settles a batch (the gateway's call, the watcher after the reply, the per-minute safety net) is reflected.
 *
 * Reading never lies about a dead watcher: a "sending" older than the deadline plus a minute is shown as failed, and old results fall away.
 */
final class ValveSets
{
    private const KIND = 'valve_sets';

    /** How long a result stays on the card. */
    private const KEEP_CONFIRMED_S = 600;
    private const KEEP_FAILED_S    = 3600;

    public function __construct(private HomeState $state)
    {
    }

    public function mark(int $userId, int $valveId, string $stateName, float $target, ?int $now = null): void
    {
        if (!in_array($stateName, ['sending', 'confirmed', 'failed'], true)) {
            return;
        }
        $now ??= time();
        $all = $this->raw($userId);
        $all[(string) $valveId] = ['state' => $stateName, 'target' => $target, 'at' => $now];
        $this->state->put($userId, self::KIND, ['valves' => $all]);
    }

    /**
     * The current results by valve id, aged: a stale "sending" becomes "failed", old results are dropped.
     *
     * @return array<int, array{state:string, target:float, age_s:int}>
     */
    public function all(int $userId, ?int $now = null): array
    {
        $now ??= time();
        $out = [];
        foreach ($this->raw($userId) as $id => $v) {
            $age = max(0, $now - (int) ($v['at'] ?? 0));
            $s   = (string) ($v['state'] ?? '');
            if ($s === 'sending' && $age > ThermostatConfirm::DEADLINE_S + 60) {
                $s = 'failed';
            }
            if (($s === 'confirmed' && $age > self::KEEP_CONFIRMED_S) || ($s === 'failed' && $age > self::KEEP_FAILED_S) || !in_array($s, ['sending', 'confirmed', 'failed'], true)) {
                continue;
            }
            $out[(int) $id] = ['state' => $s, 'target' => (float) ($v['target'] ?? 0), 'age_s' => $age];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function raw(int $userId): array
    {
        $e = $this->state->get($userId, self::KIND);

        return is_array($e['data']['valves'] ?? null) ? $e['data']['valves'] : [];
    }
}
