<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Support\RoutineGate;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Routines: scheduled tasks that can also be started by a phrase the user says or types, or by entering / leaving a place,
 * with conditions and an "ask first" mode. A routine is a scheduled_tasks row (same instruction run by the assistant, same
 * card, same pause / resume); phrase and place routines have frequency 'event' and run when REQUESTED — see
 * bin/scheduled-tasks-cron.php. Plain SQL with times passed in from PHP, so it is portable (tests run on SQLite).
 */
final class Routines
{
    /** next_run_at of a routine that only runs on request: never "due" by the clock. */
    public const NEVER = '2999-12-31 00:00:00';
    public const MAX_PHRASES = 8;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Creates a phrase or place routine. $status 'draft' waits for the user's Save; 'active' is live at once.
     *
     * @param array{label:string, instruction:string, trigger_type:string, phrases?:list<string>, trigger_config?:array<string, mixed>,
     *   conditions?:array<string, mixed>, run_mode?:string, status?:string} $spec
     */
    public function create(int $userId, int $conversationId, array $spec): int
    {
        $type = $spec['trigger_type'] === 'place' ? 'place' : 'phrase';
        $this->db->prepare(
            "INSERT INTO scheduled_tasks
                (user_id, conversation_id, label, instruction, frequency, trigger_type, phrases, trigger_config, conditions,
                 run_mode, notify_always, status, next_run_at)
             VALUES (:u, :conv, :label, :instr, 'event', :type, :phrases, :cfg, :cond, :mode, 1, :status, :next)"
        )->execute([
            ':u'      => $userId,
            ':conv'   => $conversationId,
            ':label'  => mb_substr(trim($spec['label']), 0, 80),
            ':instr'  => trim($spec['instruction']),
            ':type'   => $type,
            ':phrases' => self::joinPhrases($spec['phrases'] ?? []),
            ':cfg'    => $type === 'place' ? json_encode($spec['trigger_config'] ?? [], JSON_UNESCAPED_UNICODE) : null,
            ':cond'   => ($spec['conditions'] ?? []) === [] ? null : json_encode($spec['conditions'], JSON_UNESCAPED_UNICODE),
            ':mode'   => ($spec['run_mode'] ?? 'auto') === 'ask' ? 'ask' : 'auto',
            ':status' => ($spec['status'] ?? 'draft') === 'active' ? 'active' : 'draft',
            ':next'   => self::NEVER,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Changes some fields of one of the user's routines (any phrase / place routine; label, instruction, phrases, trigger_config,
     * conditions, run_mode). Unknown keys are ignored. Returns false if there is no such routine.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $userId, int $id, array $fields): bool
    {
        $r = $this->get($userId, $id);
        if ($r === null) {
            return false;
        }
        $set = [];
        $p   = [':id' => $id, ':u' => $userId];
        if (isset($fields['label']) && trim((string) $fields['label']) !== '') {
            $set[] = 'label = :label';
            $p[':label'] = mb_substr(trim((string) $fields['label']), 0, 80);
        }
        if (isset($fields['instruction']) && trim((string) $fields['instruction']) !== '') {
            $set[] = 'instruction = :instr';
            $p[':instr'] = trim((string) $fields['instruction']);
        }
        if (array_key_exists('phrases', $fields) && is_array($fields['phrases'])) {
            $set[] = 'phrases = :phrases';
            $p[':phrases'] = self::joinPhrases($fields['phrases']);
        }
        if (array_key_exists('trigger_config', $fields) && is_array($fields['trigger_config']) && $r['trigger_type'] === 'place') {
            $set[] = 'trigger_config = :cfg';
            $p[':cfg'] = json_encode($fields['trigger_config'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('conditions', $fields) && is_array($fields['conditions'])) {
            $set[] = 'conditions = :cond';
            $p[':cond'] = $fields['conditions'] === [] ? null : json_encode($fields['conditions'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($fields['run_mode']) && in_array($fields['run_mode'], ['auto', 'ask'], true)) {
            $set[] = 'run_mode = :mode';
            $p[':mode'] = $fields['run_mode'];
        }
        if ($set === []) {
            return true;
        }
        $this->db->prepare('UPDATE scheduled_tasks SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :u')->execute($p);

        return true;
    }

    /**
     * One phrase / place routine of this user, any status, decoded. Null if none (or it is a plain time task).
     *
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare($this->select() . " WHERE user_id = :u AND id = :id AND trigger_type IN ('phrase','place')");
        $stmt->execute([':u' => $userId, ':id' => $id]);
        $r = $stmt->fetch();

        return $r === false ? null : self::row($r);
    }

    /**
     * The user's live (active) phrase / place routines.
     *
     * @return list<array<string, mixed>>
     */
    public function active(int $userId, ?string $triggerType = null): array
    {
        $sql = $this->select() . " WHERE user_id = :u AND status = 'active' AND trigger_type IN ('phrase','place')";
        $p   = [':u' => $userId];
        if ($triggerType !== null) {
            $sql .= ' AND trigger_type = :t';
            $p[':t'] = $triggerType;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY label ASC, id ASC');
        $stmt->execute($p);

        return array_map([self::class, 'row'], $stmt->fetchAll());
    }

    /**
     * An active routine by id, exact name, or one of its phrases (case-insensitive; a phrase also matches when the user's words
     * CONTAIN it, e.g. "ok I'm leaving now" for "i'm leaving"). Null if nothing matches.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $userId, string $query): ?array
    {
        $q = self::norm($query);
        if ($q === '') {
            return null;
        }
        $all = $this->active($userId);
        foreach ($all as $r) {
            if ((string) $r['id'] === trim($query) || self::norm($r['label']) === $q) {
                return $r;
            }
        }
        foreach ($all as $r) {
            foreach ($r['phrases'] as $ph) {
                $n = self::norm($ph);
                if ($n !== '' && ($n === $q || str_contains(' ' . $q . ' ', ' ' . $n . ' '))) {
                    return $r;
                }
            }
        }

        return null;
    }

    /** draft → active (Save), active ↔ paused, anything → cancelled (Discard / delete). Returns false if there is no such routine. */
    public function setStatus(int $userId, int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'paused', 'cancelled'], true) || $this->get($userId, $id) === null) {
            return false;
        }
        $this->db->prepare('UPDATE scheduled_tasks SET status = :s, run_requested_at = NULL WHERE id = :id AND user_id = :u')
            ->execute([':s' => $status, ':id' => $id, ':u' => $userId]);

        return true;
    }

    /** Asks for a run now (the per-minute cron does it). False if it is not active or a run is already waiting. */
    public function requestRun(int $userId, int $id, string $trigger): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE scheduled_tasks SET run_requested_at = :now, run_trigger = :t
             WHERE id = :id AND user_id = :u AND status = 'active' AND run_requested_at IS NULL"
        );
        $stmt->execute([':now' => self::now(), ':t' => mb_substr($trigger, 0, 160), ':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Routines with a run waiting, oldest first (any trigger type: a time task can also be run on request).
     *
     * @return list<array<string, mixed>>
     */
    public function requested(int $limit = 50): array
    {
        $stmt = $this->db->query($this->select() . " WHERE status = 'active' AND run_requested_at IS NOT NULL ORDER BY run_requested_at ASC, id ASC LIMIT " . max(1, min(200, $limit)));

        return array_map([self::class, 'row'], $stmt->fetchAll());
    }

    /** Takes the waiting run (true only for the call that got it) and stamps the run time. */
    public function claimRequest(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE scheduled_tasks SET run_requested_at = NULL, last_run_at = :now, last_run_notified = 0
             WHERE id = :id AND status = 'active' AND run_requested_at IS NOT NULL"
        );
        $stmt->execute([':now' => self::now(), ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** Drops a waiting run without running it (conditions said no, or the user declined). */
    public function clearRequest(int $id): void
    {
        $this->db->prepare('UPDATE scheduled_tasks SET run_requested_at = NULL WHERE id = :id')->execute([':id' => $id]);
    }

    // ---------- place state (enter / leave detection) ----------

    /** @return array{inside:bool, streak:int}|null */
    public function placeState(int $taskId, int $placeId): ?array
    {
        $stmt = $this->db->prepare('SELECT inside, streak FROM routine_place_state WHERE task_id = :t AND place_id = :p');
        $stmt->execute([':t' => $taskId, ':p' => $placeId]);
        $r = $stmt->fetch();

        return $r === false ? null : ['inside' => (bool) $r['inside'], 'streak' => (int) $r['streak']];
    }

    public function savePlaceState(int $taskId, int $placeId, bool $inside, int $streak): void
    {
        $u = $this->db->prepare('UPDATE routine_place_state SET inside = :i, streak = :s, updated_at = :at WHERE task_id = :t AND place_id = :p');
        $u->execute([':i' => $inside ? 1 : 0, ':s' => $streak, ':at' => self::now(), ':t' => $taskId, ':p' => $placeId]);
        if ($u->rowCount() === 0 && $this->placeState($taskId, $placeId) === null) {
            $this->db->prepare('INSERT INTO routine_place_state (task_id, place_id, inside, streak, updated_at) VALUES (:t, :p, :i, :s, :at)')
                ->execute([':t' => $taskId, ':p' => $placeId, ':i' => $inside ? 1 : 0, ':s' => $streak, ':at' => self::now()]);
        }
    }

    // ---------- "ask first" requests ----------

    public function createRequest(int $taskId, int $userId, string $trigger): int
    {
        // one open question per routine is enough: a second trigger while one is waiting does not pile up
        $open = $this->db->prepare("SELECT id FROM routine_requests WHERE task_id = :t AND status = 'pending' LIMIT 1");
        $open->execute([':t' => $taskId]);
        $id = $open->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->db->prepare("INSERT INTO routine_requests (task_id, user_id, trigger_text, status, created_at) VALUES (:t, :u, :x, 'pending', :at)")
            ->execute([':t' => $taskId, ':u' => $userId, ':x' => mb_substr($trigger, 0, 160), ':at' => self::now()]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Pending questions (newest first) with the routine's name; older than $maxAgeHours they are expired first.
     *
     * @return list<array{id:int, task_id:int, label:string, trigger_text:string, created_at:string}>
     */
    public function pendingRequests(int $userId, int $maxAgeHours = 12): array
    {
        $this->db->prepare("UPDATE routine_requests SET status = 'expired', decided_at = :now WHERE user_id = :u AND status = 'pending' AND created_at < :old")
            ->execute([':now' => self::now(), ':u' => $userId, ':old' => gmdate('Y-m-d H:i:s', time() - $maxAgeHours * 3600)]);
        $stmt = $this->db->prepare(
            "SELECT q.id, q.task_id, t.label, q.trigger_text, q.created_at FROM routine_requests q
             JOIN scheduled_tasks t ON t.id = q.task_id WHERE q.user_id = :u AND q.status = 'pending' ORDER BY q.id DESC"
        );
        $stmt->execute([':u' => $userId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'task_id' => (int) $r['task_id'], 'label' => (string) $r['label'],
            'trigger_text' => (string) $r['trigger_text'], 'created_at' => (string) $r['created_at'],
        ], $stmt->fetchAll());
    }

    /**
     * Answers a pending question. Approving asks for the run (the cron does it); declining just closes it.
     * Returns the routine id when approved, 0 when declined, null if there was no such pending question.
     */
    public function decideRequest(int $userId, int $requestId, bool $approve): ?int
    {
        $stmt = $this->db->prepare("SELECT task_id, trigger_text FROM routine_requests WHERE id = :id AND user_id = :u AND status = 'pending'");
        $stmt->execute([':id' => $requestId, ':u' => $userId]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }
        $this->db->prepare('UPDATE routine_requests SET status = :s, decided_at = :now WHERE id = :id')
            ->execute([':s' => $approve ? 'approved' : 'declined', ':now' => self::now(), ':id' => $requestId]);
        if (!$approve) {
            return 0;
        }
        $this->requestRun($userId, (int) $r['task_id'], 'approved: ' . $r['trigger_text']);

        return (int) $r['task_id'];
    }

    // ---------- helpers ----------

    private function select(): string
    {
        return 'SELECT id, user_id, conversation_id, label, instruction, frequency, trigger_type, phrases, trigger_config, conditions,
                       run_mode, status, notify_always, run_requested_at, run_trigger, last_run_at, last_run_status, last_summary, created_at
                FROM scheduled_tasks';
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function row(array $r): array
    {
        $cfg  = json_decode((string) ($r['trigger_config'] ?? ''), true);
        $cond = json_decode((string) ($r['conditions'] ?? ''), true);

        return [
            'id'              => (int) $r['id'],
            'user_id'         => (int) $r['user_id'],
            'conversation_id' => (int) $r['conversation_id'],
            'label'           => (string) $r['label'],
            'instruction'     => (string) $r['instruction'],
            'trigger_type'    => (string) $r['trigger_type'],
            'phrases'         => self::splitPhrases((string) ($r['phrases'] ?? '')),
            'trigger'         => is_array($cfg) ? $cfg : [],
            'conditions'      => is_array($cond) ? $cond : [],
            'run_mode'        => (string) $r['run_mode'],
            'status'          => (string) $r['status'],
            'notify_always'   => (bool) $r['notify_always'],
            'run_requested_at' => $r['run_requested_at'] !== null ? (string) $r['run_requested_at'] : null,
            'run_trigger'     => $r['run_trigger'] !== null ? (string) $r['run_trigger'] : null,
            'last_run_at'     => $r['last_run_at'] !== null ? (string) $r['last_run_at'] : null,
            'last_run_status' => $r['last_run_status'] !== null ? (string) $r['last_run_status'] : null,
            'last_summary'    => $r['last_summary'] !== null ? (string) $r['last_summary'] : null,
        ];
    }

    /** @param list<string> $phrases */
    private static function joinPhrases(array $phrases): ?string
    {
        $clean = [];
        foreach ($phrases as $p) {
            $p = trim(preg_replace('/\s+/u', ' ', (string) $p) ?? '');
            if ($p !== '' && mb_strlen($p) <= 60 && !in_array(mb_strtolower($p), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $p;
            }
            if (count($clean) >= self::MAX_PHRASES) {
                break;
            }
        }

        return $clean === [] ? null : mb_substr(implode("\n", $clean), 0, 500);
    }

    /** @return list<string> */
    private static function splitPhrases(string $s): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $s)), static fn (string $x): bool => $x !== ''));
    }

    /** Lower-case, punctuation → spaces, one space: so "I'm leaving!" matches "i m leaving". */
    public static function norm(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)) ?? '') ?? '');
    }

    /**
     * Cleans a place trigger from a model: event enter|leave, place ids and / or place types (home, work …).
     *
     * @param array<string, mixed> $in
     * @return array{event:string, place_ids:list<int>, place_types:list<string>}|null null if it names no place at all
     */
    public static function normalizePlaceTrigger(array $in): ?array
    {
        $event = in_array($in['event'] ?? '', ['enter', 'leave'], true) ? (string) $in['event'] : 'leave';
        $ids   = array_values(array_unique(array_filter(array_map('intval', (array) ($in['place_ids'] ?? [])), static fn (int $x): bool => $x > 0)));
        $types = array_values(array_unique(array_filter(array_map(static fn ($t): string => mb_strtolower(trim((string) $t)), (array) ($in['place_types'] ?? [])), static fn (string $t): bool => $t !== '')));
        if ($ids === [] && $types === []) {
            return null;
        }

        return ['event' => $event, 'place_ids' => $ids, 'place_types' => $types];
    }

    /** "Leaving Home" / "Arriving at Office or any work place" — one line for the card and the model. */
    public static function describeTrigger(array $r, array $placeNames = []): string
    {
        if (($r['trigger_type'] ?? '') === 'phrase') {
            return 'Say: ' . implode(' / ', array_map(static fn (string $p): string => '“' . $p . '”', $r['phrases'] ?? []));
        }
        $t     = $r['trigger'] ?? [];
        $names = [];
        foreach ($t['place_ids'] ?? [] as $id) {
            $names[] = $placeNames[$id] ?? ('place ' . $id);
        }
        foreach ($t['place_types'] ?? [] as $ty) {
            $names[] = 'any ' . $ty . ' place';
        }

        return (($t['event'] ?? 'leave') === 'enter' ? 'Arriving at ' : 'Leaving ') . implode(' or ', $names);
    }

    /** Whether it is time-zone-free "now" in Copenhagen — for the gate. */
    public static function nowLocal(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen'));
    }

    /** The routine's last run as local time, or null. */
    public static function lastRunLocal(array $r): ?DateTimeImmutable
    {
        return $r['last_run_at'] === null ? null : (new DateTimeImmutable((string) $r['last_run_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Copenhagen'));
    }

    /** What stops this routine running now, or null (see RoutineGate). */
    public static function gate(array $r, array $context = [], ?DateTimeImmutable $nowLocal = null): ?string
    {
        return RoutineGate::check($r['conditions'] ?? [], $nowLocal ?? self::nowLocal(), self::lastRunLocal($r), $context);
    }
}
