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

    public function __construct(
        private string $apiKey,
        private string $model = 'gemini-3.1-flash-lite',
        ?callable $transport = null,
        // Low temperature keeps a factual, tool-grounded assistant from improvising
        // numbers/data. Override via GEMINI_TEMPERATURE if ever needed.
        private float $temperature = 0.2,
    ) {
        $this->transport = $transport ?? [$this, 'curlTransport'];
    }

    public static function fromEnv(?callable $transport = null): self
    {
        $key = $_ENV['GEMINI_API_KEY'] ?? '';
        if ($key === '') {
            throw new RuntimeException('GEMINI_API_KEY missing from environment.');
        }
        $model       = $_ENV['GEMINI_MODEL'] ?? 'gemini-3.1-flash-lite';
        $temperature = isset($_ENV['GEMINI_TEMPERATURE']) && $_ENV['GEMINI_TEMPERATURE'] !== ''
            ? (float) $_ENV['GEMINI_TEMPERATURE']
            : 0.2;

        return new self($key, $model, $transport, $temperature);
    }

    /**
     * Calls generateContent and returns the decoded response array.
     *
     * @param array<int, array<string, mixed>> $contents             Gemini "contents"
     * @param array<int, array<string, mixed>> $functionDeclarations  from ToolRegistry::declarations()
     */
    public function generate(array $contents, array $functionDeclarations = [], ?string $systemInstruction = null): array
    {
        $payload = [
            'contents'         => $contents,
            'generationConfig' => ['temperature' => $this->temperature],
        ];

        if ($systemInstruction !== null && $systemInstruction !== '') {
            $payload['system_instruction'] = ['parts' => [['text' => $systemInstruction]]];
        }
        if ($functionDeclarations !== []) {
            $payload['tools'] = [['function_declarations' => $functionDeclarations]];
        }

        $url = self::BASE . '/models/' . rawurlencode($this->model) . ':generateContent';
        $headers = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apiKey,
        ];

        [$status, $body] = ($this->transport)($url, $payload, $headers);

        $decoded = json_decode($body, true);
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
     * Concatenates all text parts from a response.
     */
    public static function extractText(array $response): string
    {
        $text = '';
        foreach (self::firstCandidateParts($response) as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
        }

        return $text;
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
        curl_close($ch);

        return [$status, (string) $body];
    }
}
