<?php

declare(strict_types=1);

namespace App\Home;

use App\Data\AgentCommands;
use App\Data\ApiTokens;
use App\Data\Connections;
use App\Data\HomeDevices;
use App\Data\UserSettings;
use App\Support\HomeCard;
use App\Support\Thermostats;
use App\Tools\HomeDeviceCall;
use App\Tools\SetThermostat;
use Throwable;

/**
 * The Home card's data + controls (lights, rooms, radiator thermostats through the home agent), as ONE function the web endpoints share:
 * api/home.php (the logged-in app) and api/kiosk.php (the wall display, which has its own login). The caller authenticates and passes the user id;
 * this only does what was asked.
 *
 *   GET  part=lights                                        → { ok, rooms:[…], other:[…], thermostats_known }  (the bridge's lists, live)
 *   GET  part=thermostats                                   → { ok, thermostats:[…] }            (each valve's numbers, with its Hue room)
 *   POST { action:'light', id, on?, brightness?, color? }   → one light
 *   POST { action:'group', id, on?, brightness? }           → a whole room (its grouped_light id)
 *   POST { action:'thermostat', id, target }                → a valve's target in °C (a slow valve answers "sent")
 */
final class HomeEndpoint
{
    /**
     * @param array<string, mixed> $query $_GET
     * @param array<string, mixed> $in    the decoded JSON body of a POST (empty for a GET)
     *
     * @return array{0:int, 1:array<string, mixed>} HTTP status + JSON body
     */
    public static function handle(int $userId, string $method, array $query, array $in): array
    {
        $agent    = new AgentCommands();
        $tokens   = new ApiTokens();
        $home     = new HomeDeviceCall($agent, $tokens, new HueSync(new HomeDevices(), HueSync::viaAgent($agent)), new HomeOwner($tokens, new Connections()));
        $settings = new UserSettings();

        try {
            if ($method === 'POST') {
                $action = (string) ($in['action'] ?? '');
                $id     = trim((string) ($in['id'] ?? ''));

                if ($action === 'light' || $action === 'group') {
                    if (!HomeCard::validId($id)) {
                        return [400, ['error' => 'Not a light id.']];
                    }
                    $body = HomeCard::body($action === 'group' ? array_diff_key($in, ['color' => 1]) : $in);
                    if ($body === null) {
                        return [400, ['error' => 'Nothing valid to set (on, brightness 0–100, or a known colour).']];
                    }
                    $res = $home->execute(['device' => 'hue', 'method' => 'PUT', 'path' => '/clip/v2/resource/' . ($action === 'group' ? 'grouped_light' : 'light') . '/' . $id, 'body' => $body], $userId);
                    if (isset($res['error'])) {
                        return [502, ['error' => (string) $res['error']]];
                    }
                    if (($res['http_status'] ?? 200) >= 400 || !empty($res['response']['errors'])) {
                        return [502, ['error' => 'The bridge refused that: ' . json_encode($res['response']['errors'] ?? $res['response'], JSON_UNESCAPED_UNICODE)]];
                    }

                    return [200, ['ok' => true]];
                }
                if ($action === 'thermostat') {
                    $res = (new SetThermostat($home, $settings))->execute(['thermostat' => $id, 'temperature_c' => $in['target'] ?? null], $userId);
                    if (isset($res['error']) || ($res['set'] ?? false) === false) {
                        return [502, ['error' => (string) ($res['error'] ?? 'The thermostat did not take that.')]];
                    }

                    return [200, ['ok' => true, 'set' => $res['set'], 'target_c' => $res['target_c'] ?? null]];
                }

                return [400, ['error' => 'Unknown action.']];
            }

            $part = (string) ($query['part'] ?? '');
            if ($part === 'lights') {
                $lights = self::hueList($home, $userId, 'light');
                if (isset($lights['error'])) {
                    return [502, ['error' => $lights['error']]];
                }
                // Rooms and groups are small; if either cannot be read the lights still show, just not grouped.
                $rooms  = self::hueList($home, $userId, 'room');
                $groups = self::hueList($home, $userId, 'grouped_light');
                $card   = HomeCard::lights($lights['data'], $rooms['data'] ?? [], $groups['data'] ?? []);
                // Which thermostats exist and in which room, from the saved names alone (no valve is asked): the card reserves their tiles at once.
                $known = null;
                if (trim((string) $settings->get($userId, 'thermostats')) !== '') {
                    $svc   = new ThermostatService($home, $settings);
                    $known = array_map(static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name'], 'room' => $svc->roomFor($userId, $t['name'])], Thermostats::parse($settings->get($userId, 'thermostats')));
                }

                return [200, ['ok' => true] + $card + ['thermostats_known' => $known] + (isset($rooms['error']) ? ['note' => $rooms['error']] : [])];
            }
            if ($part === 'thermostats') {
                $svc  = new ThermostatService($home, $settings);
                $list = $svc->list($userId);
                if ($list['error'] !== null) {
                    return [502, ['error' => $list['error']]];
                }
                $rows = [];
                foreach ($list['list'] as $t) {
                    $r = $svc->read($userId, $t);
                    if (isset($r['error'])) {
                        $rows[] = ['name' => $t['name'], 'id' => $t['id'], 'error' => $r['error'], 'room' => $svc->roomFor($userId, $t['name'])];
                        continue;
                    }
                    $rows[] = $r['row'] + ['room' => $svc->roomFor($userId, $t['name'])];
                }

                return [200, ['ok' => true, 'thermostats' => $rows]];
            }

            return [400, ['error' => 'Use ?part=lights or ?part=thermostats.']];
        } catch (Throwable $e) {
            error_log('HomeEndpoint: ' . $e->getMessage());

            return [500, ['error' => 'Something went wrong.']];
        }
    }

    /** One Hue list through the agent: the "data" array, or an error string. @return array{data?: list<array<string, mixed>>, error?: string} */
    private static function hueList(HomeDeviceCall $home, int $userId, string $kind): array
    {
        $res = $home->execute(['device' => 'hue', 'method' => 'GET', 'path' => '/clip/v2/resource/' . $kind], $userId);
        if (isset($res['error'])) {
            return ['error' => (string) $res['error']];
        }
        $data = $res['response']['data'] ?? null;
        if (!is_array($data)) {
            return ['error' => 'The bridge\'s ' . $kind . ' list was too long to read through the home agent.'];
        }

        return ['data' => array_values($data)];
    }
}
