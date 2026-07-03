<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Vinyls;

/**
 * Tool: mark a vinyl as heard and/or rate it (the taste signal used for matching).
 */
final class RateVinyl implements Tool
{
    public function __construct(private Vinyls $vinyls)
    {
    }

    public function name(): string
    {
        return 'rate_vinyl';
    }

    public function description(): string
    {
        return 'Records that the user has listened to a vinyl and/or how much they liked it. First '
            . 'call get_vinyls to find the record and its id. Set rating 1 (disliked) to 5 (loved); '
            . 'setting a rating also marks it heard. Use for "I listened to X and loved it" or "rate X 4 stars".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'     => ['type' => 'integer', 'description' => 'Id of the vinyl (from get_vinyls).'],
                'rating' => ['type' => 'integer', 'description' => 'How much they liked it, 1 (worst) to 5 (best).'],
                'heard'  => ['type' => 'boolean', 'description' => 'Whether they have listened to it (a rating implies true).'],
                'notes'  => ['type' => 'string', 'description' => 'Optional note about the listen.'],
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
        if (isset($arguments['rating']) && $arguments['rating'] !== '') {
            $fields['rating'] = max(1, min(5, (int) $arguments['rating']));
            $fields['heard']  = true; // rating implies heard
        }
        if (array_key_exists('heard', $arguments)) {
            $fields['heard'] = (bool) $arguments['heard'];
        }
        if (array_key_exists('notes', $arguments)) {
            $fields['notes'] = $arguments['notes'] !== '' ? (string) $arguments['notes'] : null;
        }

        if ($fields === []) {
            return ['error' => 'Provide a rating and/or mark it heard.'];
        }

        $ok = $this->vinyls->update($userId, $id, $fields);

        return $ok ? ['updated' => true, 'id' => $id] : ['updated' => false, 'error' => 'No matching vinyl found.'];
    }
}
