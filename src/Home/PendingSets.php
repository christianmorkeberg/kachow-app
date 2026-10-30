<?php

declare(strict_types=1);

namespace App\Home;

use App\Data\HomeState;

/**
 * The radiator targets that were SENT but not yet confirmed by the valves — one batch per user (everything set in the last minutes), kept in the home-state
 * cache (kind 'pending_sets'). Whoever sees a valve report its new target first — the gateway's call to api/shelly-hook.php, or the waiting watcher that
 * polls (ThermostatConfirm) — marks it here; the one that completes the batch (or takes it at the deadline) gets it and tells the user, the other finds nothing.
 */
final class PendingSets
{
    private const KIND = 'pending_sets';

    /** A batch older than this is forgotten at the next start (the watcher's own deadline is shorter). */
    private const MAX_AGE_S = 900;

    /** A valve counts as confirmed when its reported target is within this of what was asked (the valves work in half degrees). */
    public const TOLERANCE_C = 0.3;

    public function __construct(private HomeState $state)
    {
    }

    /**
     * Adds a sent target to the user's batch (a new batch when there is none or it is old). The same valve again replaces its earlier ask.
     *
     * @param array{id:int, name:string, target:float} $item
     */
    public function add(int $userId, array $item, ?int $conversationId, bool $danish): void
    {
        $e     = $this->state->get($userId, self::KIND);
        $batch = $e !== null && time() - (int) ($e['data']['at'] ?? 0) <= self::MAX_AGE_S ? $e['data'] : ['at' => time(), 'conversation_id' => $conversationId, 'danish' => $danish, 'items' => []];
        $batch['items'] = array_values(array_filter($batch['items'], static fn (array $i): bool => (int) $i['id'] !== $item['id']));
        $batch['items'][] = ['id' => $item['id'], 'name' => $item['name'], 'target' => $item['target'], 'confirmed' => false];
        if ($conversationId !== null) {
            $batch['conversation_id'] = $conversationId;
            $batch['danish']          = $danish;
        }
        $this->state->put($userId, self::KIND, $batch);
    }

    /** @return array{at:int, conversation_id:?int, danish:bool, items:list<array{id:int, name:string, target:float, confirmed:bool}>}|null */
    public function get(int $userId): ?array
    {
        $e = $this->state->get($userId, self::KIND);

        return $e !== null && is_array($e['data']['items'] ?? null) ? $e['data'] : null;
    }

    /**
     * A valve reported $target. If that is what was asked for, it is marked confirmed. Returns the whole batch — removed, and to be announced by the caller
     * — when this was the last one waiting; null otherwise (nothing pending for this valve, a different target, or others still waiting).
     *
     * @return array<string, mixed>|null
     */
    public function confirm(int $userId, int $id, float $target): ?array
    {
        $b = $this->get($userId);
        if ($b === null) {
            return null;
        }
        $hit = false;
        foreach ($b['items'] as &$i) {
            if ((int) $i['id'] === $id && !$i['confirmed'] && abs($target - (float) $i['target']) <= self::TOLERANCE_C) {
                $i['confirmed'] = true;
                $hit            = true;
            }
        }
        unset($i);
        if (!$hit) {
            return null;
        }
        if (array_filter($b['items'], static fn (array $i): bool => !$i['confirmed']) === []) {
            $taken = $this->state->take($userId, self::KIND);   // only one caller gets it
            if ($taken === null) {
                return null;
            }
            $out = $taken['data'];
            foreach ($out['items'] as &$i) {          // the stored copy predates THIS confirmation: apply it to what was taken
                if ((int) $i['id'] === $id) {
                    $i['confirmed'] = true;
                }
            }
            unset($i);

            return $out;
        }
        $this->state->put($userId, self::KIND, $b);

        return null;
    }

    /** Takes the batch as it stands (confirmed and still pending valves) — the deadline's way out. Null when someone else already announced it. @return array<string, mixed>|null */
    public function take(int $userId): ?array
    {
        return $this->state->take($userId, self::KIND)['data'] ?? null;
    }
}
