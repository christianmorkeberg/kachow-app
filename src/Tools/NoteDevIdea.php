<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\DevIdeas;
use App\Data\Users;
use App\Support\TextMatch;

/**
 * Tool: record an idea for developing the app further on the app's shared dev backlog
 * (the developer sees every user's ideas — see DevIdeas).
 */
final class NoteDevIdea implements Tool
{
    public function __construct(private DevIdeas $ideas, private Users $users)
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
            . 'idea — e.g. "for later:", "for the backlog", "idea for the app", "dev idea: …", or in Danish '
            . '"udviklingsidé", "gem denne idé", "assistenten skal kunne …". ALWAYS actually call this '
            . 'tool to persist it — do not just acknowledge in text. This is NOT the personal gift '
            . 'wishlist and NOT the shopping list. There is ONE shared backlog for the app: an idea saved '
            . 'by any user goes straight to the developer\'s list (so you can tell a non-developer user '
            . 'the developer will see it). Capture the idea in a clear sentence. Duplicates are '
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

        // Snapshot BEFORE saving. Dedupe against the WHOLE shared backlog, not just this
        // user's ideas, so the same idea from two users doesn't land twice.
        $existing = $this->ideas->listAll();

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
            'visible_to'     => $this->users->isAdmin($userId)
                ? 'you (the developer)'
                : 'the developer — it is on the app\'s shared dev backlog',
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
