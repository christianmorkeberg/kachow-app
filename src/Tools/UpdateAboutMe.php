<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Memories;

/**
 * Tool: correct/update a saved personal fact by id. Thin wrapper over
 * Data\Memories::update.
 */
final class UpdateAboutMe implements Tool
{
    public function __construct(private Memories $memories)
    {
    }

    public function name(): string
    {
        return 'update_about_me';
    }

    public function description(): string
    {
        return 'Corrects or updates a personal fact you remember about the user, by id '
            . '(see get_about_me). Use when a remembered fact has changed or was wrong. Replaces '
            . 'the whole fact with the new text.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'   => ['type' => 'integer', 'description' => 'Id of the fact to update (from get_about_me).'],
                'fact' => ['type' => 'string', 'description' => 'The corrected, self-contained fact.'],
            ],
            'required' => ['id', 'fact'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id   = isset($arguments['id']) ? (int) $arguments['id'] : 0;
        $fact = trim((string) ($arguments['fact'] ?? ''));
        if ($id <= 0) {
            return ['error' => 'A valid fact id is required.'];
        }
        if ($fact === '') {
            return ['error' => 'The updated fact text is required.'];
        }

        $ok = $this->memories->update($userId, $id, $fact);

        return $ok ? ['updated' => true, 'id' => $id] : ['error' => 'No such fact to update.'];
    }
}
