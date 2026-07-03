<?php

declare(strict_types=1);

namespace App\Music;

use RuntimeException;

/**
 * Minimal Discogs API client — used to enrich a vinyl the user names with its
 * genre, style, year, etc. Free personal-access-token auth. The HTTP transport
 * is injectable so callers are testable without network.
 *
 * Optional: if DISCOGS_TOKEN isn't set, fromEnv() returns null and callers just
 * skip enrichment (a vinyl is still saved with whatever the user provided).
 */
final class Discogs
{
    private const BASE = 'https://api.discogs.com';

    /** @var callable(string,array):array{0:int,1:string} */
    private $transport;

    public function __construct(
        private string $token,
        private string $userAgent = 'KachowAssistant/1.0 +https://assistant.kachow.dk',
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? [$this, 'curlTransport'];
    }

    public static function fromEnv(?callable $transport = null): ?self
    {
        $token = (string) ($_ENV['DISCOGS_TOKEN'] ?? '');
        if ($token === '') {
            return null;
        }
        $ua = (string) ($_ENV['DISCOGS_USER_AGENT'] ?? 'KachowAssistant/1.0 +https://assistant.kachow.dk');

        return new self($token, $ua, $transport);
    }

    /**
     * Looks up the best release match for a free-text query ("artist album") and
     * returns normalized metadata, or null if nothing matched / request failed.
     *
     * @return array{artist: ?string, title: string, genre: ?string, style: ?string, year: ?int, discogs_id: ?int, cover_url: ?string}|null
     */
    public function lookup(string $query): ?array
    {
        $url = self::BASE . '/database/search?type=release&per_page=5&q=' . rawurlencode($query);

        [$status, $body] = ($this->transport)($url, [
            'Authorization: Discogs token=' . $this->token,
            'User-Agent: ' . $this->userAgent,
        ]);

        if ($status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode($body, true);
        $result  = $decoded['results'][0] ?? null;
        if (!is_array($result)) {
            return null;
        }

        // Discogs "title" is "Artist - Album".
        $titleRaw = (string) ($result['title'] ?? '');
        $artist   = null;
        $album    = $titleRaw;
        if (str_contains($titleRaw, ' - ')) {
            [$artist, $album] = array_map('trim', explode(' - ', $titleRaw, 2));
        }

        $genre = implode(', ', array_map('strval', (array) ($result['genre'] ?? [])));
        $style = implode(', ', array_map('strval', (array) ($result['style'] ?? [])));

        return [
            'artist'     => $artist !== '' ? $artist : null,
            'title'      => $album,
            'genre'      => $genre !== '' ? $genre : null,
            'style'      => $style !== '' ? $style : null,
            'year'       => isset($result['year']) && $result['year'] !== '' ? (int) $result['year'] : null,
            'discogs_id' => isset($result['id']) ? (int) $result['id'] : null,
            'cover_url'  => $result['cover_image'] ?? ($result['thumb'] ?? null),
        ];
    }

    /**
     * @return array{0:int,1:string}
     */
    private function curlTransport(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Discogs request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }
}
