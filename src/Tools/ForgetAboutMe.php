<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Memories;

/**
 * Tool: delete a saved personal fact by id. Thin wrapper over
 * Data\Memories::delete.
 */
final class ForgetAboutMe implements Tool
{
    public function __construct(private Memories $memories)
    {
    }

    public function name(): string
    {
        return 'forget_about_me';
    }

    public function description(): string
    {
        return 'Deletes a personal fact you remember about the user, by id (see get_about_me). '
            . 'Use when the user asks you to forget something about them, or a fact is no longer true.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Id of the fact to forget (from get_about_me).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = isset($arguments['id']) ? (int) $arguments['id'] : 0;
        if ($id <= 0) {
            return ['error' => 'A valid fact id is required.'];
        }

        $ok = $this->memories->delete($userId, $id);

        return $ok ? ['forgotten' => true, 'id' => $id] : ['error' => 'No such fact to forget.'];
    }
}
