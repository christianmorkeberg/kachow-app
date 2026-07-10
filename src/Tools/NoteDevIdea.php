<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\DevIdeas;

/**
 * Tool: record an idea for developing the app further (a dev/feature backlog).
 */
final class NoteDevIdea implements Tool
{
    public function __construct(private DevIdeas $ideas)
    {
    }

    public function name(): string
    {
        return 'note_dev_idea';
    }

    public function description(): string
    {
        return 'Saves an idea for developing or improving the Kachow app itself (a feature to build '
            . 'later, a change to how it works) to a dev backlog. Use when the user proposes such an '
            . 'idea — e.g. "for later:", "for the backlog", "idea for the app". This is NOT the '
            . 'personal gift wishlist and NOT the shopping list. Capture the idea in a clear sentence.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'idea' => ['type' => 'string', 'description' => 'The idea, as a clear one-line description.'],
            ],
            'required' => ['idea'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $idea = trim((string) ($arguments['idea'] ?? ''));
        if ($idea === '') {
            return ['error' => 'Tell me the idea to note down.'];
        }

        $id    = $this->ideas->add($userId, $idea);
        $count = count($this->ideas->listForUser($userId));

        return ['saved' => true, 'id' => $id, 'idea' => $idea, 'total_ideas' => $count];
    }
}
