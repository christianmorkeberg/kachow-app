<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Vinyls;

/**
 * Tool: suggest records from the user's OWN collection to play next, based on
 * their taste. Thin — it returns the taste profile + candidate pool; the model
 * does the actual matching and explanation.
 */
final class RecommendVinyl implements Tool
{
    public function __construct(private Vinyls $vinyls)
    {
    }

    public function name(): string
    {
        return 'recommend_vinyl';
    }

    public function description(): string
    {
        return "Suggests records from the user's OWN collection to listen to next, favouring ones they "
            . "haven't heard yet that match what they've rated highly. Optionally steer with 'like' (a "
            . 'genre, style, or artist to lean toward, e.g. "modal jazz"). This returns the user\'s liked '
            . 'records, disliked records, and matching unheard candidates — recommend a few of the '
            . 'CANDIDATES and explain why each fits (shared genre/style with what they like). Avoid '
            . 'candidates that resemble what they disliked. Do not recommend records not in the candidate list.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'like'  => ['type' => 'string', 'description' => 'Optional genre/style/artist to steer toward, e.g. "modal jazz".'],
                'limit' => ['type' => 'integer', 'description' => 'Max candidates to consider (default 20).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $limit = isset($arguments['limit']) && $arguments['limit'] !== '' ? max(1, (int) $arguments['limit']) : 20;
        $like  = trim((string) ($arguments['like'] ?? ''));

        $liked = $this->vinyls->liked($userId, 4, 50);

        if ($like !== '') {
            $tokens = [$like];
        } else {
            $tokens = Vinyls::tokensFrom($liked);
        }

        $candidates = $this->vinyls->unheard($userId, $tokens !== [] ? $tokens : null, $limit);
        // If steering/taste yielded nothing, fall back to any unheard records.
        if ($candidates === []) {
            $candidates = $this->vinyls->unheard($userId, null, $limit);
        }

        if ($candidates === []) {
            return ['note' => 'No unheard records to recommend. Add some vinyls first (or you have heard them all).'];
        }

        return [
            'you_like'        => $liked,
            'you_disliked'    => $this->vinyls->disliked($userId, 2, 30),
            'taste_known'     => $liked !== [],
            'candidate_count' => count($candidates),
            'candidates'      => $candidates,
        ];
    }
}
