<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Places;

/** Tool: delete a saved place by name. */
final class DeletePlace implements Tool
{
    public function __construct(private Places $places)
    {
    }

    public function name(): string
    {
        return 'delete_place';
    }

    public function description(): string
    {
        return 'Deletes a saved place by its name. Only when the user clearly asks to remove it.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['place' => ['type' => 'string', 'description' => 'The place\'s name.']],
            'required'   => ['place'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $p = $this->places->findByName($userId, (string) ($arguments['place'] ?? ''));
        if ($p === null) {
            return ['deleted' => false, 'error' => 'No place called "' . ($arguments['place'] ?? '') . '".',
                'places' => array_map([Places::class, 'forModel'], $this->places->list($userId))];
        }
        $this->places->delete($userId, $p['id']);

        return ['deleted' => true, 'place' => $p['name']];
    }
}
