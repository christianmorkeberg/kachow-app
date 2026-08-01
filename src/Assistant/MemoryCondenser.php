<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Data\Memories;

/**
 * Keeps a user's long-term MEMORY (App\Data\Memories) tidy by merging duplicates and
 * dropping redundant facts, using a cheap model — mirrors QuickActionGenerator's
 * cron-friendly shape. Deliberately CONSERVATIVE: it only collapses items that state
 * the SAME fact (reworded, subsumed, or across languages), never rewrites distinct
 * facts, caps how much it can remove in one run, and logs only ids (never the
 * decrypted content) so the audit trail doesn't leak what encryption-at-rest protects.
 *
 * Supports a dry-run (propose without applying) so the merges can be inspected before
 * the automated job is trusted to write.
 */
final class MemoryCondenser
{
    /** Below this, condensing isn't worth a model call — leave the set alone. */
    private const MIN_ITEMS = 6;

    /** Never remove more than this fraction of a user's memories in a single run. */
    private const MAX_DELETE_RATIO = 0.5;

    private const SYSTEM =
        'You keep a personal assistant\'s long-term MEMORY tidy. You are given a numbered list of stored '
        . 'facts about ONE user. Find groups that are DUPLICATES or REDUNDANT: the SAME fact stated more '
        . 'than once, reworded, or where one fact fully CONTAINS another — INCLUDING across languages (a '
        . 'Danish and an English statement of the same fact are duplicates). For each such group, choose '
        . 'ONE item to KEEP (the clearest / most complete, by its #id) and list the other ids to DELETE. '
        . 'Optionally give merged "text" for the kept item, but ONLY if it must combine details from the '
        . 'others, written in the SAME language as the kept item. STRICT rules: never group facts that are '
        . 'genuinely different, even if related or on the same topic; when unsure, leave them separate; '
        . 'never invent facts or change meaning; keep each survivor\'s language exactly as it was. If '
        . 'nothing is clearly redundant, return an empty list. Return ONLY JSON.';

    public function __construct(
        private GeminiClient $gemini,
        private Memories $memories,
    ) {
    }

    /**
     * Proposes (and, when $apply, performs) a condense pass for one user.
     *
     * @return array{count:int, deleted:int, updated:int, applied:bool, skipped?:string,
     *               merges:array<int,array{keep:int,delete:array<int,int>,rewrote:bool}>}
     */
    public function condenseFor(int $userId, bool $apply = true): array
    {
        $items = $this->memories->all($userId);
        if (count($items) < self::MIN_ITEMS) {
            return ['count' => count($items), 'deleted' => 0, 'updated' => 0, 'applied' => false, 'merges' => [], 'skipped' => 'too few items'];
        }

        $byId  = [];
        $lines = [];
        foreach ($items as $it) {
            $byId[(int) $it['id']] = (string) $it['content'];
            $lines[] = '#' . (int) $it['id'] . ' [' . (string) $it['category'] . '] '
                . mb_substr((string) $it['content'], 0, 200);
        }

        $prompt = "FACTS:\n" . implode("\n", $lines);

        try {
            $models   = $this->gemini->models();
            $cheapest = end($models) ?: null;
            $response = $this->gemini->generate(
                [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                [],
                self::SYSTEM,
                $cheapest,
                [
                    'temperature'      => 0.1, // deterministic; this is a judgement task, not a creative one
                    'responseMimeType' => 'application/json',
                    'responseSchema'   => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'merges' => [
                                'type'  => 'ARRAY',
                                'items' => [
                                    'type'       => 'OBJECT',
                                    'properties' => [
                                        'keep'   => ['type' => 'INTEGER'],
                                        'delete' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                                        'text'   => ['type' => 'STRING'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            );
            $parsed = json_decode(GeminiClient::extractText($response), true);
        } catch (\Throwable $e) {
            error_log('MemoryCondenser user ' . $userId . ': ' . $e->getMessage());
            return ['count' => count($items), 'deleted' => 0, 'updated' => 0, 'applied' => false, 'merges' => [], 'skipped' => 'model error'];
        }

        $merges = is_array($parsed) && isset($parsed['merges']) && is_array($parsed['merges'])
            ? $parsed['merges'] : [];

        $maxDelete = (int) floor(count($items) * self::MAX_DELETE_RATIO);
        $deleted   = 0;
        $updated   = 0;
        $processed = [];   // ids already kept or deleted — never touch twice
        $done      = [];

        foreach ($merges as $m) {
            if (!is_array($m) || $deleted >= $maxDelete) {
                continue;
            }
            $keep = (int) ($m['keep'] ?? 0);
            if (!isset($byId[$keep]) || in_array($keep, $processed, true)) {
                continue;
            }

            $dels = [];
            foreach ((array) ($m['delete'] ?? []) as $d) {
                $id = (int) $d;
                if ($id !== $keep && isset($byId[$id]) && !in_array($id, $processed, true) && !in_array($id, $dels, true)) {
                    $dels[] = $id;
                }
            }
            if ($dels === []) {
                continue; // nothing to collapse — ignore no-op groups
            }

            $applied = [];
            foreach ($dels as $id) {
                if ($deleted >= $maxDelete) {
                    break;
                }
                if ($apply) {
                    $this->memories->delete($userId, $id);
                }
                $processed[] = $id;
                $applied[]   = $id;
                $deleted++;
            }
            if ($applied === []) {
                continue;
            }

            $text    = trim((string) ($m['text'] ?? ''));
            $rewrote = false;
            if ($text !== '' && mb_strlen($text) <= 500 && $text !== $byId[$keep]) {
                if ($apply) {
                    $this->memories->update($userId, $keep, $text);
                }
                $updated++;
                $rewrote = true;
            }

            $processed[] = $keep;
            $done[]      = ['keep' => $keep, 'delete' => $applied, 'rewrote' => $rewrote];
            // Audit trail: ids only — the content is encrypted at rest and must not leak to logs.
            error_log('memory-condense user ' . $userId . ($apply ? ' APPLIED' : ' DRY-RUN')
                . ': kept #' . $keep . ($rewrote ? ' (rewritten)' : '') . ', removed #' . implode(',#', $applied));
        }

        return [
            'count'   => count($items),
            'deleted' => $deleted,
            'updated' => $updated,
            'applied' => $apply,
            'merges'  => $done,
        ];
    }
}
