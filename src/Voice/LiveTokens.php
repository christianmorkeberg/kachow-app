<?php

declare(strict_types=1);

namespace App\Voice;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Mints a short-lived, single-use Gemini Live token, so the browser can open the Live WebSocket itself without
 * ever seeing the API key. POST https://generativelanguage.googleapis.com/v1alpha/auth_tokens (v1alpha only);
 * the token is the response's `name` ("auth_tokens/…"). It is used as `access_token` on the *Constrained endpoint.
 * The token is not locked to a setup here (the session config comes from our own page) — it is just short and single-use.
 */
final class LiveTokens
{
    public const URL = 'https://generativelanguage.googleapis.com/v1alpha/auth_tokens';
    public const WS  = 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContentConstrained';

    /** @var callable(string, list<string>, string): array{0:int, 1:string} */
    private $post;

    /** @param callable(string, list<string>, string): array{0:int, 1:string}|null $post [status, body] */
    public function __construct(private string $apiKey, ?callable $post = null)
    {
        $this->post = $post ?? [$this, 'curlPost'];
    }

    /**
     * @return array{token:string, expires_at:string}
     * @throws RuntimeException with Google's own message when the call is refused
     */
    public function mint(int $lifetimeSeconds = 900, int $startWithinSeconds = 90, ?DateTimeImmutable $now = null): array
    {
        $utc     = new DateTimeZone('UTC');
        $now     = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $expires = $now->modify('+' . $lifetimeSeconds . ' seconds');
        $body    = json_encode([
            'uses'                 => 1,
            'expireTime'           => $expires->format('Y-m-d\TH:i:s\Z'),
            'newSessionExpireTime' => $now->modify('+' . $startWithinSeconds . ' seconds')->format('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES);

        [$status, $raw] = ($this->post)(self::URL, ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey], (string) $body);
        $data = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : mb_substr($raw, 0, 300);
            throw new RuntimeException('Google refused the Live token (HTTP ' . $status . '): ' . $msg);
        }
        $token = is_array($data) ? (string) ($data['name'] ?? '') : '';
        if ($token === '') {
            throw new RuntimeException('Google returned no token name.');
        }

        return ['token' => $token, 'expires_at' => $expires->format('c')];
    }

    /** @return array{0:int, 1:string} */
    private function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Could not reach Google: ' . $err);
        }

        return [$status, (string) $raw];
    }
}
