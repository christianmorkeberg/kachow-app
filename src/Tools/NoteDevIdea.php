<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\DevIdeas;
use App\Support\TextMatch;

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
            . 'idea — e.g. "for later:", "for the backlog", "idea for the app", or in Danish '
            . '"udviklingsidé", "gem denne idé", "assistenten skal kunne …". ALWAYS actually call this '
            . 'tool to persist it — do not just acknowledge in text. This is NOT the personal gift '
            . 'wishlist and NOT the shopping list. Capture the idea in a clear sentence. Duplicates are '
            . 'checked: the result lists the existing backlog and flags likely duplicates — if the idea '
            . '(or a translation/rewording of it) is already there, tell the user instead of re-adding.';
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

        $existing = $this->ideas->listForUser($userId); // snapshot BEFORE saving

        // Deterministic block for same-language exact / near-exact duplicates (this can't
        // see across languages — that's the model's job, handled below).
        foreach ($existing as $e) {
            if (TextMatch::similar($idea, $e['idea'])) {
                return [
                    'saved'     => false,
                    'duplicate' => true,
                    'matched'   => ['id' => $e['id'], 'idea' => $e['idea']],
                    'message'   => 'That idea is already on the backlog — tell the user it is already saved '
                        . '(as "' . $e['idea'] . '") and do not add a duplicate.',
                ];
            }
        }

        $id = $this->ideas->add($userId, $idea);

        return [
            'saved'          => true,
            'id'             => $id,
            'idea'           => $idea,
            'total_ideas'    => count($existing) + 1,
            'existing_ideas' => array_map(
                static fn (array $e): array => ['id' => $e['id'], 'idea' => $e['idea']],
                $existing,
            ),
            'dedupe_hint'    => 'Compare `idea` against existing_ideas. If any of them means the SAME thing '
                . '— even in another language or reworded (e.g. English "Send to developer" vs Danish '
                . '"Send til udvikler") — this is a DUPLICATE: call remove_dev_idea with id=' . $id
                . ' to undo it, then tell the user it is already saved (name the existing one). Otherwise '
                . 'just confirm briefly that it was saved.',
        ];
    }
}
