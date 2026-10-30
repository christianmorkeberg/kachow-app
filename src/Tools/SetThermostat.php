<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\HomeState;
use App\Data\UserSettings;
use App\Home\ThermostatConfirm;
use App\Home\ThermostatService;
use App\Support\AfterReply;
use App\Support\Thermostats;

/**
 * Tool: set a radiator thermostat's target temperature — "set the living room to 21", "turn the heating down to 17". The valve
 * (a Shelly BLU TRV) is reached through the home agent's "shelly" device, i.e. the BLU gateway on the home network.
 */
final class SetThermostat implements Tool
{
    /** How long to wait for a valve to confirm before answering "sent" (the command stays queued on the Pico, see HomeDeviceCall::executeSoft). */
    private const CONFIRM_WAIT_S = 4.0;

    public function __construct(private Tool $home, private UserSettings $settings, private ?HomeState $state = null)
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
        $call = [
            'device' => 'shelly', 'method' => 'POST', 'path' => '/rpc/BluTrv.Call',
            'body'   => ['id' => $t['id'], 'method' => 'TRV.SetTarget', 'params' => ['id' => 0, 'target_C' => $target]],
        ];
        // The Pico runs ONE command at a time and a sleepy valve takes 10+ s to confirm: wait only briefly, leave the command queued (it keeps for 3 min)
        // and answer "sent" — "all radiators to 15" then takes seconds, not 45 (report #66). Falls back to the full wait when the home call cannot do it.
        $res = $this->home instanceof HomeDeviceCall ? $this->home->executeSoft($call, $userId, self::CONFIRM_WAIT_S) : $this->home->execute($call, $userId);
        if (($res['queued'] ?? false) === true) {
            (new ThermostatService($this->home, $this->settings, $this->state))->noteTarget($userId, $t['id'], $target);
            $watching = $this->watchConfirmation($userId, $t['id'], $t['name'], $target);

            return [
                'set' => 'sent', 'thermostat' => $t['name'], 'target_c' => $target,
                'will_notify' => $watching,
                'note' => $watching
                    ? 'The command is queued for the valve, which has not confirmed yet — it sleeps between reports and applies it within a few minutes. The user gets a notification '
                        . 'automatically as soon as the valves have confirmed (or if one does not). Say in one short line that it is sent and that you will let them know when it is done — '
                        . 'do not offer to check later.'
                    : 'The command is queued for the valve, which has not confirmed yet — it sleeps between reports and applies it within a minute or two. '
                        . 'Say it is sent (not that it failed) and offer to check with get_thermostats in a few minutes.',
            ];
        }
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

        (new ThermostatService($this->home, $this->settings, $this->state))->noteTarget($userId, $t['id'], $target);

        return ['set' => true, 'thermostat' => $t['name'], 'target_c' => $target, 'note' => 'The valve moves to it within a minute or two. Say it is set in a few words.'];
    }

    /**
     * Queues the follow-up that waits for the valve to report the new target and then tells the user (App\Support\AfterReply — only where the chat can release the
     * connection early). All valves of one turn share ONE watcher. False when there is no way to follow up, so the model must not promise a notification.
     */
    private function watchConfirmation(int $userId, int $id, string $name, float $target): bool
    {
        if (!AfterReply::enabled()) {
            return false;
        }
        $home     = $this->home;
        $settings = $this->settings;
        $state    = $this->state;
        AfterReply::collect('thermostat_confirm', ['id' => $id, 'name' => $name, 'target' => $target], static function (array $items, array $ctx) use ($userId, $home, $settings, $state): void {
            $svc = new ThermostatService($home, $settings, $state);
            $say = static function (string $text) use ($ctx): void {
                if (!empty($ctx['conversation_id'])) {
                    (new \App\Data\Conversations())->addMessage((int) $ctx['conversation_id'], 'assistant', $text);
                }
            };
            $notify = static function (string $title, string $body) use ($userId, $ctx): void {
                \App\Notify\Notifier::fromEnv()->notify($userId, \App\Notify\NotificationTypes::FOLLOW_UP, $title, $body, !empty($ctx['conversation_id']) ? '/?conv=' . (int) $ctx['conversation_id'] : null);
            };
            (new ThermostatConfirm(
                static fn (int $id): ?float => $svc->targetOf($userId, $id),
                $notify,
                $say,
                static function (int $s): void { sleep($s); },
                static fn (): int => time()
            ))->run($items, (bool) ($ctx['danish'] ?? false));
        }, 480);

        return true;
    }

    private static function timedOut(string $error): bool
    {
        return stripos($error, 'ETIMEDOUT') !== false || stripos($error, 'timed out') !== false || stripos($error, 'No answer from the home agent') !== false;
    }
}
