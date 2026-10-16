<?php

declare(strict_types=1);

namespace App\Home;

use App\Data\AgentCommands;
use App\Data\HomeDevices;
use App\Support\HueEntries;

/**
 * Keeps the saved Hue name -> id list (HomeDevices) fresh. Three ways in:
 *  - refresh(): ask the bridge (through the Pico) for every list — the daily cron and the
 *    refresh_home_devices tool;
 *  - ingest(): store a list the assistant just fetched anyway, for free.
 */
final class HueSync
{
    public const DEVICE = 'hue';

    /** kind => the CLIP v2 list path that returns every resource of that kind. */
    public const KIND_PATHS = [
        'light' => '/clip/v2/resource/light',
        'room'  => '/clip/v2/resource/room',
        'zone'  => '/clip/v2/resource/zone',
        'scene' => '/clip/v2/resource/scene',
    ];

    /** @var callable(int, string):?array decoded JSON of GET <path> through the Pico, or null on failure */
    private $fetch;

    public function __construct(private HomeDevices $devices, callable $fetch)
    {
        $this->fetch = $fetch;
    }

    /** The real fetch: queue a GET for the Pico and wait for its answer. */
    public static function viaAgent(AgentCommands $commands, float $timeoutSeconds = 20.0): callable
    {
        return static function (int $userId, string $path) use ($commands, $timeoutSeconds): ?array {
            $id  = $commands->enqueue($userId, self::DEVICE, 'GET', $path);
            $res = $commands->awaitResult($userId, $id, $timeoutSeconds);
            if ($res['state'] !== 'done' || $res['http_status'] !== 200) {
                return null;
            }
            $decoded = json_decode((string) $res['response'], true);

            return is_array($decoded) ? $decoded : null;
        };
    }

    /**
     * Re-reads every list from the bridge and saves it.
     *
     * @return array{saved: array<string,int>, failed: list<string>}
     */
    public function refresh(int $userId): array
    {
        $saved  = [];
        $failed = [];
        foreach (self::KIND_PATHS as $kind => $path) {
            $decoded = ($this->fetch)($userId, $path);
            // A scene list can exceed what the Pico sends back and arrive cut short (not valid
            // JSON) — skip that kind rather than save half of it, and say so.
            if ($decoded === null || !$this->store($userId, $kind, $decoded)) {
                $failed[] = $kind;
                continue;
            }
            $saved[$kind] = count($this->entriesOf($kind, $decoded));
        }

        return ['saved' => $saved, 'failed' => $failed];
    }

    /**
     * Saves a list the assistant fetched for its own reasons (a GET of exactly one of the list
     * paths). Anything else — a single resource, a PUT, an error answer — is ignored.
     *
     * @param array<string, mixed> $decoded
     */
    public function ingest(int $userId, string $method, string $path, ?int $httpStatus, array $decoded): void
    {
        if (strtoupper($method) !== 'GET' || $httpStatus !== 200) {
            return;
        }
        $kind = array_search(strtok($path, '?'), self::KIND_PATHS, true);
        if ($kind === false) {
            return;
        }
        $this->store($userId, (string) $kind, $decoded);
    }

    /** @param array<string, mixed> $decoded */
    private function store(int $userId, string $kind, array $decoded): bool
    {
        if (!isset($decoded['data']) || !is_array($decoded['data']) || !array_is_list($decoded['data'])) {
            return false;
        }
        if (!empty($decoded['errors'])) {
            return false;
        }
        $this->devices->replaceKind($userId, self::DEVICE, $kind, $this->entriesOf($kind, $decoded));

        return true;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array{type:string, name:string, id:string, group:?string}>
     */
    private function entriesOf(string $kind, array $decoded): array
    {
        return array_values(array_filter(
            HueEntries::fromItems($decoded['data'] ?? []),
            static fn (array $e): bool => $e['type'] === $kind
        ));
    }
}
