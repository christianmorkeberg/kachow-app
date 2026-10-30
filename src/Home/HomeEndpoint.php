<?php

declare(strict_types=1);

namespace App\Home;

use App\Data\AgentCommands;
use App\Data\ApiTokens;
use App\Data\Connections;
use App\Data\HomeDevices;
use App\Data\HomeState;
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
 *
 * Reads are served from the home-state cache (App\Data\HomeState, refreshed every 5 minutes by bin/home-state-cron.php) so they do not queue behind the
 * Pico's one-command-at-a-time line; add fresh=1 for a live read. A response from the cache carries `cached_at` (unix seconds). A light change drops the
 * cached lights so the next read is live.
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
        $state    = new HomeState(null, new HomeOwner($tokens, new Connections()));

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

                    $state->forget($userId, 'lights');

                    return [200, ['ok' => true]];
                }
                if ($action === 'thermostat') {
                    $res = (new SetThermostat($home, $settings, $state))->execute(['thermostat' => $id, 'temperature_c' => $in['target'] ?? null], $userId);
                    if (isset($res['error']) || ($res['set'] ?? false) === false) {
                        return [502, ['error' => (string) ($res['error'] ?? 'The thermostat did not take that.')]];
                    }

                    return [200, ['ok' => true, 'set' => $res['set'], 'target_c' => $res['target_c'] ?? null]];
                }

                return [400, ['error' => 'Unknown action.']];
            }

            $part  = (string) ($query['part'] ?? '');
            $fresh = !empty($query['fresh']);
            if ($part === 'lights') {
                $hit = $fresh ? null : $state->fresh($userId, 'lights');
                if ($hit !== null) {
                    return [200, $hit['data'] + ['cached_at' => $hit['at']]];
                }
                [$status, $body] = self::liveLights($home, $settings, $userId);
                if ($status === 200) {
                    $state->put($userId, 'lights', $body);
                    $body['cached_at'] = time();
                }

                return [$status, $body];
            }
            if ($part === 'thermostats') {
                $svc  = new ThermostatService($home, $settings, $state);
                $read = $svc->rows($userId, $fresh);
                if ($read['error'] !== null) {
                    return [502, ['error' => $read['error']]];
                }
                $rows = array_map(static fn (array $r): array => $r + ['room' => $svc->roomFor($userId, (string) ($r['name'] ?? ''))], $read['rows']);

                return [200, ['ok' => true, 'thermostats' => $rows, 'cached_at' => $read['at']]];
            }

            return [400, ['error' => 'Use ?part=lights or ?part=thermostats.']];
        } catch (Throwable $e) {
            error_log('HomeEndpoint: ' . $e->getMessage());

            return [500, ['error' => 'Something went wrong.']];
        }
    }

    /**
     * The Home card's lights, read live from the bridge (three Pico calls).
     *
     * @return array{0:int, 1:array<string, mixed>}
     */
    private static function liveLights(HomeDeviceCall $home, UserSettings $settings, int $userId): array
    {
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

    /**
     * Reads the lights and every valve LIVE and stores them in the cache — what bin/home-state-cron.php does for each user every 5 minutes.
     *
     * @return array{lights: bool, valves: int, errors: list<string>}
     */
    public static function refresh(int $userId): array
    {
        $agent    = new AgentCommands();
        $tokens   = new ApiTokens();
        $owner    = new HomeOwner($tokens, new Connections());
        $home     = new HomeDeviceCall($agent, $tokens, new HueSync(new HomeDevices(), HueSync::viaAgent($agent)), $owner);
        $settings = new UserSettings();
        $state    = new HomeState(null, $owner);
        $errors   = [];

        [$status, $body] = self::liveLights($home, $settings, $userId);
        if ($status === 200) {
            $state->put($userId, 'lights', $body);
        } else {
            $errors[] = 'lights: ' . (string) ($body['error'] ?? $status);
        }
        $read = (new ThermostatService($home, $settings, $state))->rows($userId, true);
        if ($read['error'] !== null) {
            $errors[] = 'valves: ' . $read['error'];
        }

        return ['lights' => $status === 200, 'valves' => count(array_filter($read['rows'], static fn (array $r): bool => !isset($r['error']))), 'errors' => $errors];
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
