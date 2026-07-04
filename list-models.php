<?php

declare(strict_types=1);

/**
 * One-off helper: lists the Gemini models this API key can use for
 * generateContent. Reads GEMINI_API_KEY from .env (never prints it). Run on the
 * server, then delete. Not wired into the app.
 *
 *   php list-models.php
 */

require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->load();

$key = $_ENV['GEMINI_API_KEY'] ?? '';
if ($key === '') {
    fwrite(STDERR, "GEMINI_API_KEY missing from .env\n");
    exit(1);
}

$ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['x-goog-api-key: ' . $key],
    CURLOPT_RETURNTRANSFER => true,
]);
$body   = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $status < 200 || $status >= 300) {
    fwrite(STDERR, "Request failed (HTTP $status): $body\n");
    exit(1);
}

$data = json_decode((string) $body, true);
echo "Models usable for generateContent:\n";
foreach ($data['models'] ?? [] as $m) {
    if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) {
        echo '  ' . str_replace('models/', '', (string) $m['name']) . "\n";
    }
}
