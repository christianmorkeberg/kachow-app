<?php

declare(strict_types=1);

namespace App\Energy;

use RuntimeException;

/**
 * Minimal client for the Tuya Cloud (IoT Core) API — enough to read smart plugs that meter power. Signed requests (HMAC-SHA256 over
 * client id, token, timestamp and the canonical request), a cached access token, and the devices linked to the user's Tuya / Smart Life
 * app account. Read-only: it never sends a command to a device. The HTTP transport is injectable so it is testable without network.
 *
 * Credentials come from the server's .env: TUYA_ACCESS_ID, TUYA_ACCESS_SECRET, TUYA_REGION (eu | weu | us | ueast | cn | in; default eu =
 * "Central Europe"). The secret never leaves the server — each request is signed with it.
 */
final class Tuya
{
    /** The data-centre hosts, by the region names Tuya's console uses. */
    public const HOSTS = [
        'eu'    => 'https://openapi.tuyaeu.com',        // Central Europe
        'weu'   => 'https://openapi-weaz.tuyaeu.com',   // Western Europe
        'us'    => 'https://openapi.tuyaus.com',        // Western America
        'ueast' => 'https://openapi-ueaz.tuyaus.com',   // Eastern America
        'cn'    => 'https://openapi.tuyacn.com',
        'in'    => 'https://openapi.tuyain.com',
    ];

    /** Status codes a metering plug reports, and the scale (decimals) Tuya uses for them when the device's specification does not say. */
    private const DEFAULT_SCALE = ['cur_power' => 1, 'cur_voltage' => 1, 'cur_current' => 0, 'add_ele' => 3];

    private ?string $token = null;
    private int $tokenUntil = 0;

    /** @var callable(string,string,array<string,string>,?string):array{0:int,1:string} */
    private $transport;

    /** @var callable():int */
    private $clock;

    public function __construct(
        private string $accessId,
        private string $accessSecret,
        private string $region = 'eu',
        ?callable $transport = null,
        ?callable $clockMs = null,
    ) {
        $this->transport = $transport ?? [$this, 'curl'];
        $this->clock     = $clockMs ?? static fn (): int => (int) round(microtime(true) * 1000);
    }

    public static function fromEnv(?callable $transport = null): self
    {
        $env = static function (string $k): string {
            $v = $_ENV[$k] ?? getenv($k);

            return is_string($v) ? trim($v) : '';
        };

        return new self($env('TUYA_ACCESS_ID'), $env('TUYA_ACCESS_SECRET'), strtolower($env('TUYA_REGION')) ?: 'eu', $transport);
    }

    public function isConfigured(): bool
    {
        return $this->accessId !== '' && $this->accessSecret !== '' && isset(self::HOSTS[$this->region]);
    }

    /** The Kachow user whose Tuya account this is (the owner of the server's credentials): TUYA_USER_ID, default 1. */
    public static function ownerUserId(): int
    {
        $v = $_ENV['TUYA_USER_ID'] ?? getenv('TUYA_USER_ID');

        return is_string($v) && ctype_digit(trim($v)) && (int) trim($v) > 0 ? (int) trim($v) : 1;
    }

    /**
     * Every device linked to the app account: id, name, online, category, and (when Tuya includes it) the current status codes.
     *
     * @return list<array{id:string, name:string, online:bool, category:string, product:string, status:array<string,mixed>}>
     */
    public function devices(): array
    {
        $out  = [];
        $last = '';
        for ($page = 0; $page < 10; $page++) {
            $q = ['size' => '50'];
            if ($last !== '') {
                $q['last_row_key'] = $last;
            }
            $res = $this->call('GET', '/v1.0/iot-01/associated-users/devices', $q);
            foreach ((array) ($res['devices'] ?? []) as $d) {
                if (!is_array($d) || !isset($d['id'])) {
                    continue;
                }
                $out[] = [
                    'id'       => (string) $d['id'],
                    'name'     => (string) ($d['name'] ?? $d['id']),
                    'online'   => (bool) ($d['online'] ?? false),
                    'category' => (string) ($d['category'] ?? ''),
                    'product'  => (string) ($d['product_name'] ?? ''),
                    'status'   => self::statusMap($d['status'] ?? []),
                ];
            }
            $last = (string) ($res['last_row_key'] ?? '');
            if (empty($res['has_more']) || $last === '') {
                break;
            }
        }

        return $out;
    }

    /** @return array<string,mixed> status code => raw value */
    public function status(string $deviceId): array
    {
        return self::statusMap($this->call('GET', '/v1.0/devices/' . rawurlencode($deviceId) . '/status'));
    }

    /** The scale / unit of each status code, from the device's specification: code => ['scale'=>int,'unit'=>string]. Empty if unreadable. */
    public function scales(string $deviceId): array
    {
        try {
            $spec = $this->call('GET', '/v1.0/devices/' . rawurlencode($deviceId) . '/specifications');
        } catch (RuntimeException) {
            return [];
        }
        $out = [];
        foreach (array_merge((array) ($spec['status'] ?? []), (array) ($spec['functions'] ?? [])) as $s) {
            if (!is_array($s) || !isset($s['code'])) {
                continue;
            }
            $v = is_string($s['values'] ?? null) ? json_decode($s['values'], true) : ($s['values'] ?? null);
            if (is_array($v) && isset($v['scale'])) {
                $out[(string) $s['code']] = ['scale' => (int) $v['scale'], 'unit' => (string) ($v['unit'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * What a plug's raw status says in real units, or null when the device reports no power data (a plain on/off plug).
     *
     * @param array<string,mixed> $status
     * @param array<string,array{scale:int,unit:string}> $scales
     * @return array{switch:?bool, watts:?float, volts:?float, amps:?float, kwh_total:?float}|null
     */
    public static function reading(array $status, array $scales = []): ?array
    {
        if (!array_key_exists('cur_power', $status) && !array_key_exists('add_ele', $status)) {
            return null;
        }
        $num = static function (string $code, float $perUnit = 1.0) use ($status, $scales): ?float {
            if (!isset($status[$code]) || !is_numeric($status[$code])) {
                return null;
            }
            $scale = $scales[$code]['scale'] ?? self::DEFAULT_SCALE[$code];

            return (float) $status[$code] / (10 ** $scale) * $perUnit;
        };
        // Current is reported in mA (scale 0) on most plugs: convert to amps. A plug that already reports A has scale >= 1 in its spec.
        $currentUnit = $scales['cur_current']['unit'] ?? 'mA';
        $amps        = $num('cur_current', strcasecmp($currentUnit, 'A') === 0 ? 1.0 : 0.001);
        $switch      = null;
        foreach (['switch_1', 'switch'] as $code) {
            if (isset($status[$code])) {
                $switch = (bool) $status[$code];
                break;
            }
        }

        return [
            'switch'    => $switch,
            'watts'     => ($w = $num('cur_power')) !== null ? round($w, 1) : null,
            'volts'     => ($v = $num('cur_voltage')) !== null ? round($v, 1) : null,
            'amps'      => $amps !== null ? round($amps, 3) : null,
            'kwh_total' => ($k = $num('add_ele')) !== null ? round($k, 3) : null,
        ];
    }

    /** @param mixed $list [{code, value}] @return array<string,mixed> */
    public static function statusMap(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $row) {
            if (is_array($row) && isset($row['code'])) {
                $out[(string) $row['code']] = $row['value'] ?? null;
            }
        }

        return $out;
    }

    // ---------- signed transport ----------

    /** @param array<string,string> $query @return array<string,mixed> the "result" of a successful response */
    private function call(string $method, string $path, array $query = [], ?string $body = null, bool $retried = false): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Tuya is not configured on the server (TUYA_ACCESS_ID / TUYA_ACCESS_SECRET / TUYA_REGION).');
        }
        $token = str_starts_with($path, '/v1.0/token') ? null : $this->accessToken();
        [$status, $raw] = ($this->transport)($method, self::HOSTS[$this->region] . self::canonicalUrl($path, $query), $this->headers($method, $path, $query, $body, $token), $body);
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            throw new RuntimeException('Tuya answered something unreadable (HTTP ' . $status . ').');
        }
        if (empty($j['success'])) {
            // 1010 = token invalid / expired: fetch a fresh one once.
            if (!$retried && $token !== null && (int) ($j['code'] ?? 0) === 1010) {
                $this->token = null;

                return $this->call($method, $path, $query, $body, true);
            }
            throw new RuntimeException('Tuya refused: ' . (string) ($j['msg'] ?? 'unknown error') . ' (code ' . (string) ($j['code'] ?? '?') . ').');
        }

        return is_array($j['result'] ?? null) ? $j['result'] : [];
    }

    private function accessToken(): string
    {
        $now = (int) (($this->clock)() / 1000);
        if ($this->token !== null && $now < $this->tokenUntil - 60) {
            return $this->token;
        }
        $res = $this->call('GET', '/v1.0/token', ['grant_type' => '1']);
        if (!isset($res['access_token'])) {
            throw new RuntimeException('Tuya gave no access token.');
        }
        $this->token      = (string) $res['access_token'];
        $this->tokenUntil = $now + (int) ($res['expire_time'] ?? 7200);

        return $this->token;
    }

    /** @param array<string,string> $query */
    private static function canonicalUrl(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }
        ksort($query);

        return $path . '?' . urldecode(http_build_query($query));
    }

    /** @param array<string,string> $query @return array<string,string> */
    private function headers(string $method, string $path, array $query, ?string $body, ?string $token): array
    {
        $t     = (string) ($this->clock)();
        $nonce = '';
        $toSign = strtoupper($method) . "\n" . hash('sha256', $body ?? '') . "\n" . "\n" . self::canonicalUrl($path, $query);
        $sign   = strtoupper(hash_hmac('sha256', $this->accessId . ($token ?? '') . $t . $nonce . $toSign, $this->accessSecret));
        $h = ['client_id' => $this->accessId, 'sign' => $sign, 'sign_method' => 'HMAC-SHA256', 't' => $t, 'nonce' => $nonce, 'Content-Type' => 'application/json'];
        if ($token !== null) {
            $h['access_token'] = $token;
        }

        return $h;
    }

    /** @param array<string,string> $headers @return array{0:int,1:string} */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_map(static fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Could not reach Tuya: ' . $err);
        }

        return [$status, (string) $raw];
    }
}
