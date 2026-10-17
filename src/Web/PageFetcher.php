<?php

declare(strict_types=1);

namespace App\Web;

/**
 * Fetches one public web page for the assistant, refusing anything that could reach inside the
 * server's own network (SSRF): only http(s) on ports 80/443, no credentials in the URL, and every
 * address the host resolves to must be a PUBLIC IP. The connection is pinned to the checked IP
 * (so DNS can't swap it afterwards), redirects are followed by hand with the same checks at each
 * hop, and the body is capped. Readable types only — HTML, text, JSON, XML.
 */
final class PageFetcher
{
    public const MAX_BYTES     = 2_000_000;
    public const MAX_REDIRECTS = 3;
    public const TIMEOUT_S     = 15;
    private const USER_AGENT   = 'KachowAssistant/1.0 (personal assistant; reads a page the user asked about)';

    /** @var callable(string): list<string> */
    private $resolve;
    /** @var callable(string, string, int): array{status:int, headers:array<string,string>, body:string, error:?string} */
    private $transport;

    /**
     * @param (callable(string $host): list<string>)|null $resolve  host → IPs (default: DNS)
     * @param (callable(string $url, string $ip, int $port): array)|null $transport (default: cURL pinned to $ip)
     */
    public function __construct(?callable $resolve = null, ?callable $transport = null)
    {
        $this->resolve   = $resolve ?? [self::class, 'dnsResolve'];
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /**
     * @return array{ok: bool, error?: string, status?: int, final_url?: string, content_type?: string, body?: string}
     */
    public function fetch(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->check($url);
            if (isset($target['error'])) {
                return ['ok' => false, 'error' => $target['error']];
            }
            $res = ($this->transport)($url, $target['ip'], $target['port']);
            if (($res['error'] ?? null) !== null) {
                return ['ok' => false, 'error' => 'Could not load the page: ' . $res['error']];
            }
            $status = (int) $res['status'];
            if ($status >= 300 && $status < 400 && isset($res['headers']['location'])) {
                $url = self::absolute($url, $res['headers']['location']);
                continue;
            }
            if ($status >= 400) {
                return ['ok' => false, 'error' => 'The site answered HTTP ' . $status . '.', 'status' => $status];
            }
            $ct = strtolower($res['headers']['content-type'] ?? 'text/html');
            if (!preg_match('~text/|html|json|xml~', $ct)) {
                return ['ok' => false, 'error' => 'That link is not a web page (' . strtok($ct, ';') . '), so it cannot be read as text.'];
            }

            return [
                'ok'           => true,
                'status'       => $status,
                'final_url'    => $url,
                'content_type' => $ct,
                'body'         => self::toUtf8((string) $res['body'], $ct),
            ];
        }

        return ['ok' => false, 'error' => 'Too many redirects.'];
    }

    /**
     * Validates one URL and picks the IP to connect to.
     *
     * @return array{ip: string, port: int}|array{error: string}
     */
    public function check(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            return ['error' => 'That is not a valid web address.'];
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['error' => 'Only http and https links can be opened.'];
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return ['error' => 'Links with a username or password in them are not opened.'];
        }
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port !== 80 && $port !== 443) {
            return ['error' => 'Only the normal web ports are allowed.'];
        }
        $host = strtolower(trim($p['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return ['error' => 'That address points inside a private network, so it is not opened.'];
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($ips === []) {
            return ['error' => 'That site could not be found (no address for ' . $host . ').'];
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return ['error' => 'That address points inside a private network, so it is not opened.'];
            }
        }

        return ['ip' => $ips[0], 'port' => $port];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // Private (10/8, 172.16/12, 192.168/16, fc00::/7) and reserved (0/8, 127/8, 169.254/16, ::1, …).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Not covered by the flags on every PHP version: carrier-grade NAT and IPv4-mapped IPv6.
        if (preg_match('~^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.~', $ip) === 1) {
            return false;
        }
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            return self::isPublicIp(substr($ip, 7));
        }

        return true;
    }

    /** @return list<string> */
    public static function dnsResolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $v6  = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($v6 as $r) {
            if (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /** @return array{status:int, headers:array<string,string>, body:string, error:?string} */
    public static function curlTransport(string $url, string $ip, int $port): array
    {
        $host    = (string) parse_url($url, PHP_URL_HOST);
        $headers = [];
        $body    = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE        => [trim($host, '[]') . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.5', 'Accept-Language: da,en;q=0.8'],
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($c, string $chunk) use (&$body): int {
                $body .= $chunk;

                return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk);   // 0 aborts: page too big
            },
        ]);
        curl_exec($ch);
        $err    = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($err !== 0 && !($err === CURLE_WRITE_ERROR && strlen($body) > self::MAX_BYTES)) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => curl_strerror($err)];
        }

        return ['status' => $status, 'headers' => $headers, 'body' => substr($body, 0, self::MAX_BYTES), 'error' => null];
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return $b['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = isset($b['path']) ? rtrim(dirname($b['path']), '/') : '';

        return $origin . $dir . '/' . $location;
    }

    private static function toUtf8(string $body, string $contentType): string
    {
        $charset = null;
        if (preg_match('~charset=([\w-]+)~i', $contentType, $m) === 1) {
            $charset = strtoupper($m[1]);
        } elseif (preg_match('~<meta[^>]+charset=["\']?([\w-]+)~i', substr($body, 0, 4096), $m) === 1) {
            $charset = strtoupper($m[1]);
        }
        if ($charset !== null && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
    }
}
