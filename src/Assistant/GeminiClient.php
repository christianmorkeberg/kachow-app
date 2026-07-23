<?php

declare(strict_types=1);

namespace App\Assistant;

use RuntimeException;

/**
 * Minimal REST client for the Gemini generateContent endpoint.
 *
 * No official PHP SDK exists — this speaks raw JSON over HTTP. The HTTP transport
 * is injectable (a callable) so the assistant loop is fully testable without
 * network; production uses the built-in cURL transport.
 */
final class GeminiClient
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /** @var callable(string,array,array):array{0:int,1:string} */
    private $transport;

    /** @var list<string> Ordered model chain: primary first, then fallbacks. */
    private array $models;

    /**
     * @param array<int, string>|string $models One model, or a chain (primary first).
     */
    public function __construct(
        private string $apiKey,
        array|string $models = ['gemini-3.5-flash'],
        ?callable $transport = null,
        // Low temperature keeps a factual, tool-grounded assistant from improvising
        // numbers/data. Override via GEMINI_TEMPERATURE if ever needed.
        private float $temperature = 0.2,
        // Caps the model's internal "thinking" tokens. These are mostly lookup/tool
        // tasks that don't need deep reasoning, and thinking dominates the response
        // latency, so a low budget is much faster. null = leave the model default;
        // 0 = thinking off; N>0 = token cap; -1 = dynamic. Set via GEMINI_THINKING_BUDGET.
        private ?int $thinkingBudget = null,
    ) {
        $this->models = self::normalizeModels($models);
        $this->transport = $transport ?? [$this, 'curlTransport'];
    }

    public static function fromEnv(?callable $transport = null): self
    {
        $key = $_ENV['GEMINI_API_KEY'] ?? '';
        if ($key === '') {
            throw new RuntimeException('GEMINI_API_KEY missing from environment.');
        }
        $primary   = $_ENV['GEMINI_MODEL'] ?? 'gemini-3.5-flash';
        $fallbacks = $_ENV['GEMINI_FALLBACK_MODELS'] ?? 'gemini-2.5-flash,gemini-3.1-flash-lite';
        $models    = array_merge([$primary], explode(',', $fallbacks));

        $temperature = isset($_ENV['GEMINI_TEMPERATURE']) && $_ENV['GEMINI_TEMPERATURE'] !== ''
            ? (float) $_ENV['GEMINI_TEMPERATURE']
            : 0.2;

        $budget = isset($_ENV['GEMINI_THINKING_BUDGET']) && $_ENV['GEMINI_THINKING_BUDGET'] !== ''
            ? (int) $_ENV['GEMINI_THINKING_BUDGET']
            : null;

        return new self($key, $models, $transport, $temperature, $budget);
    }

    /**
     * The model chain (primary first), so the loop can fall back on rate limits.
     *
     * @return list<string>
     */
    public function models(): array
    {
        return $this->models;
    }

    /**
     * Calls generateContent and returns the decoded response array.
     *
     * @param array<int, array<string, mixed>> $contents             Gemini "contents"
     * @param array<int, array<string, mixed>> $functionDeclarations  from ToolRegistry::declarations()
     * @param string|null                       $model                Override; defaults to the primary.
     *
     * @throws RateLimitException on HTTP 429 (quota) or 503 (overloaded).
     */
    public function generate(
        array $contents,
        array $functionDeclarations = [],
        ?string $systemInstruction = null,
        ?string $model = null,
        ?array $generationConfig = null,
    ): array {
        $model ??= $this->models[0];

        $genConfig = array_merge(['temperature' => $this->temperature], $generationConfig ?? []);
        // Apply the configured thinking budget, preserving any thinkingConfig keys
        // (e.g. includeThoughts) the caller already set.
        if ($this->thinkingBudget !== null) {
            $genConfig['thinkingConfig'] = array_merge(
                $genConfig['thinkingConfig'] ?? [],
                ['thinkingBudget' => $this->thinkingBudget]
            );
        }

        $payload = [
            'contents'         => $contents,
            'generationConfig' => $genConfig,
        ];

        if ($systemInstruction !== null && $systemInstruction !== '') {
            $payload['system_instruction'] = ['parts' => [['text' => $systemInstruction]]];
        }
        if ($functionDeclarations !== []) {
            $payload['tools'] = [['function_declarations' => $functionDeclarations]];
        }

        $url = self::BASE . '/models/' . rawurlencode($model) . ':generateContent';
        $headers = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apiKey,
        ];

        [$status, $body] = ($this->transport)($url, $payload, $headers);

        $decoded = json_decode($body, true);
        if ($status === 429 || $status === 503) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            throw new RateLimitException(
                'Gemini model "' . $model . '" is rate-limited (HTTP ' . $status . ')'
                . ($message !== null ? ': ' . $message : '')
            );
        }
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            throw new RuntimeException('Gemini API error: ' . ($message ?? ('HTTP ' . $status)));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Gemini API returned invalid JSON.');
        }

        return $decoded;
    }

    /**
     * @param array<int, string>|string $models
     * @return list<string>
     */
    private static function normalizeModels(array|string $models): array
    {
        $list = [];
        foreach ((array) $models as $m) {
            $m = trim((string) $m);
            if ($m !== '' && !in_array($m, $list, true)) {
                $list[] = $m;
            }
        }

        return $list !== [] ? $list : ['gemini-3.5-flash'];
    }

    /**
     * Extracts functionCall parts from a response.
     *
     * @return array<int, array{name: string, args: array<string, mixed>}>
     */
    public static function extractFunctionCalls(array $response): array
    {
        $calls = [];
        foreach (self::firstCandidateParts($response) as $part) {
            if (isset($part['functionCall']['name'])) {
                $call = [
                    'name' => (string) $part['functionCall']['name'],
                    'args' => (array) ($part['functionCall']['args'] ?? []),
                ];
                // Preserve the call id so the functionResponse can be paired to it.
                if (isset($part['functionCall']['id'])) {
                    $call['id'] = (string) $part['functionCall']['id'];
                }
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * Concatenates the reply text parts, skipping "thought" parts (which are only
     * present when thinkingConfig.includeThoughts is on — those go to extractThoughts).
     */
    public static function extractText(array $response): string
    {
        $text = '';
        foreach (self::firstCandidateParts($response) as $part) {
            if (isset($part['text']) && empty($part['thought'])) {
                $text .= (string) $part['text'];
            }
        }

        return $text;
    }

    /**
     * Concatenates any thought-summary parts (present only when includeThoughts is on).
     * Used to capture the model's reasoning into per-turn diagnostics.
     */
    public static function extractThoughts(array $response): string
    {
        $text = '';
        foreach (self::firstCandidateParts($response) as $part) {
            if (isset($part['text']) && !empty($part['thought'])) {
                $text .= (string) $part['text'];
            }
        }

        return trim($text);
    }

    /**
     * The model's response content, verbatim, with role forced to "model".
     *
     * Must be echoed back into the next request's contents unchanged — thinking
     * models (e.g. gemini-3.x) attach a `thoughtSignature` to functionCall parts
     * that the API requires on the follow-up call. Rebuilding the turn from just
     * name+args drops it and the API rejects the request.
     *
     * @return array<string, mixed>
     */
    public static function firstCandidateContent(array $response): array
    {
        $content = $response['candidates'][0]['content'] ?? ['parts' => []];
        $content['role'] = 'model';

        // A no-argument functionCall comes back as args:[] (empty), which re-encodes
        // as a JSON array and the API rejects the follow-up ("function_call ... cannot
        // start list"). Force empty args to a JSON object. NB: iterate the array
        // directly — `$content['parts'] ?? []` would return a copy and break the ref.
        if (isset($content['parts']) && is_array($content['parts'])) {
            foreach ($content['parts'] as &$part) {
                if (isset($part['functionCall']) && ($part['functionCall']['args'] ?? null) === []) {
                    $part['functionCall']['args'] = new \stdClass();
                }
            }
            unset($part);
        }

        return $content;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function firstCandidateParts(array $response): array
    {
        return $response['candidates'][0]['content']['parts'] ?? [];
    }

    /**
     * @return array{0:int,1:string} [statusCode, body]
     */
    private function curlTransport(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Gemini request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Timing breakdown so we can see WHERE Gemini calls spend time:
        //   conn = DNS+TCP, tls = TLS handshake  → network distance to Google
        //   think = time after the request is sent until the first byte → Gemini itself
        // A fresh handle each call means every round re-pays conn+tls (no keep-alive).
        $ms      = static fn (int $k): int => (int) round(((float) curl_getinfo($ch, $k)) * 1000);
        $conn    = $ms(CURLINFO_CONNECT_TIME);
        $appconn = $ms(CURLINFO_APPCONNECT_TIME);      // 0 if the connection was reused
        $ttfb    = $ms(CURLINFO_STARTTRANSFER_TIME);
        $total   = $ms(CURLINFO_TOTAL_TIME);
        $tls     = $appconn > 0 ? max(0, $appconn - $conn) : 0;
        $think   = max(0, $ttfb - ($appconn > 0 ? $appconn : $conn));
        self::$lastCurlMs = ['conn' => $conn, 'tls' => $tls, 'think' => $think, 'total' => $total];
        error_log(sprintf(
            'timing gemini: conn=%dms tls=%dms think=%dms total=%dms http=%d',
            $conn, $tls, $think, $total, $status
        ));

        curl_close($ch);

        return [$status, (string) $body];
    }

    /** @var array{conn:int, tls:int, think:int, total:int}|null Timing of the last cURL call. */
    private static ?array $lastCurlMs = null;

    /** Timing (ms) of the most recent Gemini HTTP call, or null (e.g. injected transport). */
    public static function lastCallTiming(): ?array
    {
        return self::$lastCurlMs;
    }
}
