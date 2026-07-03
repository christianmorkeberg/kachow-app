<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Vinyls;
use App\Music\Discogs;

/**
 * Tool: judge whether a record the user is considering (not necessarily owned)
 * fits their taste. Looks the record up on Discogs for its genre/style and
 * returns it alongside the user's taste profile; the model gives the verdict.
 */
final class AssessVinyl implements Tool
{
    public function __construct(
        private Vinyls $vinyls,
        private ?Discogs $discogs = null,
    ) {
    }

    public function name(): string
    {
        return 'assess_vinyl';
    }

    public function description(): string
    {
        return 'Judges whether a record the user is considering (that they may not own) is a good fit '
            . "for their taste. Provide the album title and artist. It looks up the record's genre/style "
            . 'on Discogs and returns it with the records the user has rated highly AND the ones they '
            . 'disliked — use both: note shared genre/style with what they like, and flag if it resembles '
            . 'something they disliked. If Discogs cannot identify it, the user can supply the genre.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'title'  => ['type' => 'string', 'description' => 'Album title being considered.'],
                'artist' => ['type' => 'string', 'description' => 'Artist of the album.'],
                'genre'  => ['type' => 'string', 'description' => 'Optional genre/style if you already know it (used if Discogs cannot find the record).'],
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

        $record = null;
        if ($this->discogs !== null) {
            $record = $this->discogs->lookup(trim($artist . ' ' . $title));
        }

        if ($record === null) {
            $genre = trim((string) ($arguments['genre'] ?? ''));
            if ($genre === '') {
                return ['error' => "Couldn't identify that record on Discogs — tell me its genre/style and I'll assess the fit."];
            }
            $record = [
                'artist' => $artist !== '' ? $artist : null,
                'title'  => $title,
                'genre'  => $genre,
                'style'  => null,
                'year'   => null,
            ];
        } elseif (($g = trim((string) ($arguments['genre'] ?? ''))) !== '') {
            $record['genre'] = $g;
        }

        $liked = $this->vinyls->liked($userId, 4, 50);

        return [
            'record'       => $record,
            'you_like'     => $liked,
            'you_disliked' => $this->vinyls->disliked($userId, 2, 30),
            'taste_known'  => $liked !== [],
        ];
    }
}
