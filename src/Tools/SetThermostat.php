<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserSettings;
use App\Support\Thermostats;

/**
 * Tool: set a radiator thermostat's target temperature — "set the living room to 21", "turn the heating down to 17". The valve
 * (a Shelly BLU TRV) is reached through the home agent's "shelly" device, i.e. the BLU gateway on the home network.
 */
final class SetThermostat implements Tool
{
    public function __construct(private Tool $home, private UserSettings $settings)
    {
    }

    public function name(): string
    {
        return 'set_thermostat';
    }

    public function description(): string
    {
        return 'Sets the target temperature of one of the user\'s radiator thermostats (4–30 °C, half degrees): "set the living room to 21", "heating down to '
            . '17", Danish "sæt varmen i stuen til 21". Pass `thermostat` (its name or id, as in get_thermostats — call get_thermostats first if you do not know them) unless they have only one, and `temperature_c`. '
            . 'The valve can take a minute to confirm; a result of set="sent" means the command was delivered and will apply shortly — say it is sent and offer to check later with '
            . 'get_thermostats. "Turn it off" is a LOW target (e.g. 5 °C — frost protection), never a number outside 4–30.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'thermostat'    => ['type' => 'string', 'description' => 'The thermostat\'s name or id. Omit when there is only one.'],
                'temperature_c' => ['type' => 'number', 'description' => 'Target temperature in °C, 4–30.'],
            ],
            'required' => ['temperature_c'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $target = Thermostats::target($arguments['temperature_c'] ?? null);
        if ($target === null) {
            return ['error' => 'The target must be a number between ' . Thermostats::MIN_C . ' and ' . Thermostats::MAX_C . ' °C.'];
        }
        $all = Thermostats::parse($this->settings->get($userId, 'thermostats'));
        $t   = Thermostats::resolve($all, (string) ($arguments['thermostat'] ?? ''));
        if ($t === null) {
            return ['error' => 'Which thermostat? They are: ' . implode(', ', array_map(static fn (array $x): string => $x['name'], $all)) . '.'];
        }
        $res = $this->home->execute([
            'device' => 'shelly', 'method' => 'POST', 'path' => '/rpc/BluTrv.Call',
            'body'   => ['id' => $t['id'], 'method' => 'TRV.SetTarget', 'params' => ['id' => 0, 'target_C' => $target]],
        ], $userId);
        if (isset($res['error'])) {
            // A valve sleeps between its reports, so the gateway often holds the answer longer than the Pico waits. If the gateway
            // itself still answers, the command was delivered: it is sent, not failed.
            if (self::timedOut((string) $res['error'])) {
                $ping = $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetStatus?id=' . $t['id']], $userId);
                if (!isset($ping['error']) && !empty($ping['response']['paired'])) {
                    return [
                        'set' => 'sent', 'thermostat' => $t['name'], 'target_c' => $target,
                        'note' => 'The command is sent to the valve, but the valve has not confirmed yet — it sleeps between reports and applies it within a minute or two. '
                            . 'Say it is sent (not that it failed) and offer to check with get_thermostats in a few minutes.',
                    ];
                }
            }

            return ['set' => false, 'thermostat' => $t['name'], 'error' => $res['error']];
        }
        if (($res['http_status'] ?? 200) >= 400 || isset($res['response']['error'])) {
            return ['set' => false, 'thermostat' => $t['name'], 'error' => 'The gateway refused it: ' . json_encode($res['response'] ?? $res, JSON_UNESCAPED_UNICODE)];
        }

        return ['set' => true, 'thermostat' => $t['name'], 'target_c' => $target, 'note' => 'The valve moves to it within a minute or two. Say it is set in a few words.'];
    }

    private static function timedOut(string $error): bool
    {
        return stripos($error, 'ETIMEDOUT') !== false || stripos($error, 'timed out') !== false || stripos($error, 'No answer from the home agent') !== false;
    }
}
