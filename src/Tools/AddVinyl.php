<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Vinyls;
use App\Music\Discogs;

/**
 * Tool: add a vinyl to the collection, auto-enriched from Discogs (genre, style,
 * year, cover) when available.
 */
final class AddVinyl implements Tool
{
    public function __construct(
        private Vinyls $vinyls,
        private ?Discogs $discogs = null,
    ) {
    }

    public function name(): string
    {
        return 'add_vinyl';
    }

    public function description(): string
    {
        return 'Adds a vinyl record to the user\'s collection. Provide the album title and the artist; '
            . 'genre, style, year and cover are looked up automatically from Discogs, so you usually '
            . 'do not need to supply them. Mark heard=true if they have already listened to it. Use '
            . 'when the user wants to add/catalog a record they own.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'title'  => ['type' => 'string', 'description' => 'Album title, e.g. "Kind of Blue".'],
                'artist' => ['type' => 'string', 'description' => 'Artist, e.g. "Miles Davis".'],
                'genre'  => ['type' => 'string', 'description' => 'Optional genre override (else from Discogs).'],
                'style'  => ['type' => 'string', 'description' => 'Optional style override (else from Discogs).'],
                'year'   => ['type' => 'integer', 'description' => 'Optional year override.'],
                'heard'  => ['type' => 'boolean', 'description' => 'True if they have already listened to it (default false).'],
                'rating' => ['type' => 'integer', 'description' => 'Optional rating 1 (worst) to 5 (best) if already rated.'],
                'notes'  => ['type' => 'string', 'description' => 'Optional note.'],
            ],
            'required' => ['title'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $title  = trim((string) ($arguments['title'] ?? ''));
        $artist = trim((string) ($arguments['artist'] ?? ''));
        if ($title === '') {
            return ['error' => 'An album title is required.'];
        }

        $meta     = ['artist' => $artist !== '' ? $artist : null, 'title' => $title];
        $enriched = false;

        if ($this->discogs !== null) {
            $found = $this->discogs->lookup(trim($artist . ' ' . $title));
            if ($found !== null) {
                $enriched = true;
                if ($artist === '' && $found['artist'] !== null) {
                    $meta['artist'] = $found['artist'];
                }
                $meta['genre']      = $found['genre'];
                $meta['style']      = $found['style'];
                $meta['year']       = $found['year'];
                $meta['discogs_id'] = $found['discogs_id'];
                $meta['cover_url']  = $found['cover_url'];
            }
        }

        // Explicit user values override enrichment.
        foreach (['genre', 'style', 'notes'] as $key) {
            if (isset($arguments[$key]) && $arguments[$key] !== '') {
                $meta[$key] = (string) $arguments[$key];
            }
        }
        if (isset($arguments['year']) && $arguments['year'] !== '') {
            $meta['year'] = (int) $arguments['year'];
        }
        if (isset($arguments['rating']) && $arguments['rating'] !== '') {
            $meta['rating'] = max(1, min(5, (int) $arguments['rating']));
        }
        $meta['heard'] = !empty($arguments['heard']);

        if (($meta['artist'] ?? null) === null || $meta['artist'] === '') {
            return ['error' => 'Please include the artist for this record.'];
        }

        $id = $this->vinyls->add($userId, $meta);

        return [
            'added'    => true,
            'id'       => $id,
            'enriched' => $enriched,
            'vinyl'    => [
                'artist' => $meta['artist'],
                'title'  => $meta['title'],
                'genre'  => $meta['genre'] ?? null,
                'style'  => $meta['style'] ?? null,
                'year'   => $meta['year'] ?? null,
            ],
        ];
    }
}
