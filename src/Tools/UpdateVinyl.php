<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Vinyls;

/**
 * Tool: correct details of a vinyl in the collection.
 */
final class UpdateVinyl implements Tool
{
    public function __construct(private Vinyls $vinyls)
    {
    }

    public function name(): string
    {
        return 'update_vinyl';
    }

    public function description(): string
    {
        return 'Edits details of a vinyl already in the collection (artist, title, genre, style, year, '
            . 'notes). First call get_vinyls to find its id, then pass only the fields to change. Use to '
            . 'fix wrong metadata. To rate or mark heard, use rate_vinyl instead.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'     => ['type' => 'integer', 'description' => 'Id of the vinyl (from get_vinyls).'],
                'artist' => ['type' => 'string', 'description' => 'New artist.'],
                'title'  => ['type' => 'string', 'description' => 'New album title.'],
                'genre'  => ['type' => 'string', 'description' => 'New genre.'],
                'style'  => ['type' => 'string', 'description' => 'New style.'],
                'year'   => ['type' => 'integer', 'description' => 'New year.'],
                'notes'  => ['type' => 'string', 'description' => 'New note.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = isset($arguments['id']) ? (int) $arguments['id'] : 0;
        if ($id <= 0) {
            return ['error' => 'A valid vinyl id is required (from get_vinyls).'];
        }

        $fields = [];
        foreach (['artist', 'title', 'genre', 'style', 'notes'] as $key) {
            if (isset($arguments[$key]) && $arguments[$key] !== '') {
                $fields[$key] = (string) $arguments[$key];
            }
        }
        if (isset($arguments['year']) && $arguments['year'] !== '') {
            $fields['year'] = (int) $arguments['year'];
        }

        if ($fields === []) {
            return ['error' => 'Specify at least one field to change (artist, title, genre, style, year, notes).'];
        }

        $ok = $this->vinyls->update($userId, $id, $fields);

        return $ok ? ['updated' => true, 'id' => $id] : ['updated' => false, 'error' => 'No matching vinyl found.'];
    }
}
