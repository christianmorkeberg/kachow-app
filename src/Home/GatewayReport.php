<?php

declare(strict_types=1);

namespace App\Home;

use App\Support\Thermostats;

/**
 * What the gateway's script says when a radiator valve has reported (api/shelly-hook.php?t=…&id=200&target=16&cur=21.6&pos=0&bat=100&rssi=-70&ts=…): validates it,
 * puts it in the cached readings at once (so the cache is as fresh as the valve's own 5-minute report), and settles any PendingSets whose target the valve now has —
 * telling the user if that completed the batch. Pure apart from what is injected; the endpoint only authenticates and calls this.
 */
final class GatewayReport
{
    public function __construct(private ThermostatService $service, private PendingSets $pending, private $deliver)
    {
    }

    /**
     * @param array<string, mixed> $q the query string
     *
     * @return array{ok: bool, error?: string, id?: int, confirmed?: bool, announced?: bool}
     */
    public function accept(int $userId, array $q): array
    {
        $id = isset($q['id']) && is_numeric($q['id']) ? (int) $q['id'] : 0;
        if (!in_array($id, Thermostats::PROBE_IDS, true)) {
            return ['ok' => false, 'error' => 'unknown valve id'];
        }
        $num = static function (string $k, float $min, float $max) use ($q): ?float {
            if (!isset($q[$k]) || !is_numeric($q[$k])) {
                return null;
            }
            $v = (float) $q[$k];

            return $v >= $min && $v <= $max ? $v : null;
        };
        $target  = $num('target', Thermostats::MIN_C - 1, Thermostats::MAX_C + 1);
        $current = $num('cur', -30, 80);
        if ($target === null && $current === null) {
            return ['ok' => false, 'error' => 'no temperature in the report'];
        }
        $pos = $num('pos', 0, 100);
        $bat = $num('bat', 0, 100);
        $rssi = $num('rssi', -140, 0);
        $ts  = $num('ts', 1_000_000_000, 4_000_000_000);

        $this->service->acceptReport($userId, [
            'id' => $id, 'target_c' => $target, 'current_c' => $current,
            'valve_open_pct' => $pos !== null ? (int) round($pos) : null,
            'battery_pct' => $bat !== null ? (int) round($bat) : null,
            'signal_dbm' => $rssi !== null ? (int) round($rssi) : null,
            'updated_ts' => $ts !== null ? (int) $ts : null,
        ]);

        $announced = false;
        if ($target !== null) {
            $done = $this->pending->confirm($userId, $id, $target);
            if ($done !== null) {
                ($this->deliver)(ThermostatConfirm::text($done), isset($done['conversation_id']) ? (int) $done['conversation_id'] : null);
                $announced = true;
            }
        }

        return ['ok' => true, 'id' => $id, 'announced' => $announced];
    }
}
