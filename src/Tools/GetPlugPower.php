<?php

declare(strict_types=1);

namespace App\Tools;

use App\Energy\Tuya;
use Throwable;

/**
 * Tool: what the metering smart plugs (Tuya) draw RIGHT NOW — watts, volts, amps, the plug's running kWh total, on/off, online.
 * Read-only: it never switches a plug. Only for the user whose Tuya account the server is connected to (TUYA_USER_ID).
 */
final class GetPlugPower implements Tool
{
    /** @var array<string, array<string,array{scale:int,unit:string}>> */
    private static array $scaleCache = [];

    public function __construct(private Tuya $tuya)
    {
    }

    public function name(): string
    {
        return 'get_plug_power';
    }

    public function description(): string
    {
        return 'Reads what the smart plugs that measure power (Tuya) are drawing right now: watts, volts, amps, on/off, whether online, and '
            . 'the plug\'s own running kWh total. Use it for "how much power is the plug / washing machine / freezer using", "is the plug on", '
            . '"what is drawing power". Pass plug to pick one by (part of) its name. Plain on/off plugs that do not measure are not listed. '
            . 'It only reads — to switch something use the usual device tools. For usage over time or cost use get_plug_energy.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['plug' => ['type' => 'string', 'description' => 'Part of the plug\'s name (e.g. "washer"). Omit for all metering plugs.']],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if ($userId !== Tuya::ownerUserId() || !$this->tuya->isConfigured()) {
            return ['error' => 'No smart plug is connected for you yet.'];
        }
        $want = mb_strtolower(trim((string) ($arguments['plug'] ?? '')));
        try {
            $plugs = [];
            $other = 0;
            foreach ($this->tuya->devices() as $d) {
                $status = $d['status'] !== [] ? $d['status'] : ($d['online'] ? $this->tuya->status($d['id']) : []);
                if (!array_key_exists('cur_power', $status) && !array_key_exists('add_ele', $status)) {
                    $other++;
                    continue;
                }
                if ($want !== '' && !str_contains(mb_strtolower($d['name']), $want)) {
                    continue;
                }
                $scales = self::$scaleCache[$d['id']] ??= $this->tuya->scales($d['id']);
                $r      = Tuya::reading($status, $scales);
                $plugs[] = [
                    'name' => $d['name'], 'online' => $d['online'], 'on' => $r['switch'] ?? null,
                    'watts' => $r['watts'] ?? null, 'volts' => $r['volts'] ?? null, 'amps' => $r['amps'] ?? null, 'total_kwh' => $r['kwh_total'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            return ['error' => 'Could not read the plugs: ' . $e->getMessage()];
        }
        if ($plugs === []) {
            return ['plugs' => [], 'note' => $want !== '' ? 'No metering plug matches that name.' : 'No plug that measures power was found on the Tuya account.', 'other_devices' => $other];
        }

        return ['plugs' => $plugs, 'note' => 'An offline plug shows its last known values. total_kwh is the plug\'s own counter, not a period.'];
    }
}
