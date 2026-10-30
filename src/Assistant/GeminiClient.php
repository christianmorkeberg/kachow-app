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

    /** @var callable(string,array,array,?int):array{0:int,1:string} (4th arg: timeout ms) */
    private $transport;

    /** @var list<string> Ordered model chain: primary first, then fallbacks. */
    private array $models;

    /**
     * @param array<int, string>|string $models One model, or a chain (primary first).
     */
    public function __construct(
        private string $apiKey,
        array|string $models = ['gemini-3.6-flash'],
        ?callable $transport = null,
        // Caps the model's internal "thinking" tokens. These are mostly lookup/tool
        // tasks that don't need deep reasoning, and thinking dominates the response
        // latency, so a low budget is much faster. null = leave the model default;
        // 0 = thinking off; N>0 = token cap; -1 = dynamic. Set via GEMINI_THINKING_BUDGET.
        // Superseded by $thinkingLevel when that's set — Google is retiring numeric
        // budgets in favor of a coarse level ("minimal"/"low"/"medium"/"high").
        private ?int $thinkingBudget = null,
        private ?string $thinkingLevel = null,
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
        $primary   = $_ENV['GEMINI_MODEL'] ?? 'gemini-3.6-flash';
        $fallbacks = $_ENV['GEMINI_FALLBACK_MODELS'] ?? 'gemini-2.5-flash,gemini-3.1-flash-lite';
        $models    = array_merge([$primary], explode(',', $fallbacks));

        $budget = isset($_ENV['GEMINI_THINKING_BUDGET']) && $_ENV['GEMINI_THINKING_BUDGET'] !== ''
            ? (int) $_ENV['GEMINI_THINKING_BUDGET']
            : null;

        $level = isset($_ENV['GEMINI_THINKING_LEVEL']) && $_ENV['GEMINI_THINKING_LEVEL'] !== ''
            ? (string) $_ENV['GEMINI_THINKING_LEVEL']
            : null;

        return new self($key, $models, $transport, $budget, $level);
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
     * @param int|null                          $timeoutMs            Cap on this call's wall time
     *                                                                (null = the 60s default).
     * @param string|null                       $functionCallingMode  'NONE' forces a text answer
     *                                                                (tools stay declared).
     *
     * @throws RateLimitException on HTTP 429 (quota) or 503 (overloaded).
     * @throws GeminiTimeoutException when the call doesn't answer within $timeoutMs.
     */
    public function generate(
        array $contents,
        array $functionDeclarations = [],
        ?string $systemInstruction = null,
        ?string $model = null,
        ?array $generationConfig = null,
        ?int $timeoutMs = null,
        ?string $functionCallingMode = null,
        bool $googleSearch = false,
    ): array {
        $model ??= $this->models[0];

        // No sampling overrides (temperature/top_p/top_k) — Gemini has ignored custom
        // values since 3.6-flash, and upcoming models reject them outright.
        $genConfig = $generationConfig ?? [];
        // Apply the configured thinking level/budget, preserving any thinkingConfig
        // keys (e.g. includeThoughts) the caller already set. thinkingLevel wins when
        // both are configured — it's the field Google is moving to; thinkingBudget is
        // the legacy numeric cap, still needed for models that don't support levels.
        if ($this->thinkingLevel !== null) {
            $genConfig['thinkingConfig'] = array_merge(
                $genConfig['thinkingConfig'] ?? [],
                ['thinkingLevel' => $this->thinkingLevel]
            );
        } elseif ($this->thinkingBudget !== null) {
            $genConfig['thinkingConfig'] = array_merge(
                $genConfig['thinkingConfig'] ?? [],
                ['thinkingBudget' => $this->thinkingBudget]
            );
        }

        $payload = ['contents' => $contents];
        if ($genConfig !== []) {
            // PHP's [] is a JSON array, not object — only attach it non-empty so an
            // empty config never serializes as "generationConfig":[] (Gemini expects
            // an object there).
            $payload['generationConfig'] = $genConfig;
        }

        if ($systemInstruction !== null && $systemInstruction !== '') {
            $payload['system_instruction'] = ['parts' => [['text' => $systemInstruction]]];
        }
        if ($functionDeclarations !== []) {
            $payload['tools'] = [['function_declarations' => $functionDeclarations]];
            if ($functionCallingMode !== null) {
                $payload['tool_config'] = ['function_calling_config' => ['mode' => $functionCallingMode]];
            }
        }

        if ($googleSearch) {
            // Gemini's built-in Google Search grounding. Used on its OWN call (WebSearch), never
            // alongside the app's function tools, so it works whatever the model allows mixing.
            $payload['tools'][] = ['google_search' => new \stdClass()];
        }

        $url = self::BASE . '/models/' . rawurlencode($model) . ':generateContent';
        $headers = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apiKey,
        ];

        [$status, $body] = ($this->transport)($url, $payload, $headers, $timeoutMs);

        $decoded = json_decode($body, true);
        $apiMessage = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
        if (($status < 200 || $status >= 300) && AiCreditsException::matches(is_string($apiMessage) ? $apiMessage : null)) {
            throw new AiCreditsException('Gemini API error: ' . $apiMessage);   // out of credits: no other model will do better
        }
        if ($status === 429 || $status === 503) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            throw new RateLimitException(
                'Gemini model "' . $model . '" is rate-limited (HTTP ' . $status . ')'
                . ($message !== null ? ': ' . $message : ''),
                $status,
                $message
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

        return $list !== [] ? $list : ['gemini-3.6-flash'];
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
     * The sites a grounded (Google Search) answer drew on: [{title, url}], de-duplicated by title.
     * The url is Google's redirect link; the title is normally the site's domain.
     *
     * @return list<array{title: string, url: string}>
     */
    public static function extractSources(array $response): array
    {
        $out = [];
        $chunks = $response['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [];
        foreach (is_array($chunks) ? $chunks : [] as $chunk) {
            $title = trim((string) ($chunk['web']['title'] ?? ''));
            $url   = trim((string) ($chunk['web']['uri'] ?? ''));
            if ($title === '' || $url === '' || isset($out[$title])) {
                continue;
            }
            $out[$title] = ['title' => $title, 'url' => $url];
        }

        return array_values($out);
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
    private function curlTransport(string $url, array $payload, array $headers, ?int $timeoutMs = null): array
    {
        $timeoutMs ??= 60000;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_NOSIGNAL          => true, // sub-second timeouts on some builds need this
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                error_log(sprintf('timing gemini: TIMEOUT after %dms', $timeoutMs));
                throw new GeminiTimeoutException('Gemini did not answer within ' . $timeoutMs . 'ms: ' . $error);
            }
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
