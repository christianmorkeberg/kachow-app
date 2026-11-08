<?php

declare(strict_types=1);

namespace App\Recruiter;

use App\Database;
use PDO;

/**
 * The recruiter links (one per application) and everything that hangs off them: the chat log, the messages left for the owner, the caps. The token IS the access:
 * 40 random characters, shown only to the owner, and the page never lists links. Time is UTC throughout.
 */
final class RecruiterLinks
{
    /** The defaults for a new link — override per link (update tool) or for all new ones with RECRUITER_MESSAGE_CAP / RECRUITER_TOKEN_CAP in the .env. */
    public const DEFAULT_MESSAGE_CAP = 30;
    public const DEFAULT_TOKEN_CAP   = 200000;
    /** The chat log is kept this many days. */
    public const RETENTION_DAYS = 14;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public static function defaultMessageCap(): int
    {
        return self::envInt('RECRUITER_MESSAGE_CAP', self::DEFAULT_MESSAGE_CAP);
    }

    public static function defaultTokenCap(): int
    {
        return self::envInt('RECRUITER_TOKEN_CAP', self::DEFAULT_TOKEN_CAP);
    }

    /**
     * @return array<string, mixed> the link, with its token
     */
    public function create(int $userId, string $label, ?string $company, ?string $role, ?string $posting, ?string $matchSummary = null, ?string $expiresAt = null, ?int $messageCap = null, ?int $tokenCap = null): array
    {
        $token = bin2hex(random_bytes(20));
        $this->db->prepare(
            'INSERT INTO recruiter_links (user_id, token, label, company, role_title, job_posting, match_summary, expires_at, message_cap, token_cap, created_at)
             VALUES (:u, :t, :l, :c, :r, :p, :m, :e, :mc, :tc, :now)'
        )->execute([
            ':u' => $userId, ':t' => $token, ':l' => mb_substr(trim($label), 0, 160), ':c' => $company !== null ? mb_substr(trim($company), 0, 160) : null,
            ':r' => $role !== null ? mb_substr(trim($role), 0, 160) : null, ':p' => $posting !== null ? mb_substr($posting, 0, 20000) : null, ':m' => $matchSummary,
            ':e' => $expiresAt, ':mc' => max(1, $messageCap ?? self::defaultMessageCap()), ':tc' => max(1000, $tokenCap ?? self::defaultTokenCap()), ':now' => gmdate('Y-m-d H:i:s'),
        ]);

        return $this->byId((int) $this->db->lastInsertId()) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM recruiter_links WHERE token = :t');
        $stmt->execute([':t' => $token]);
        $r = $stmt->fetch();

        return $r === false ? null : $r;
    }

    /** @return array<string, mixed>|null */
    public function byId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM recruiter_links WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch();

        return $r === false ? null : $r;
    }

    /** ok | revoked | expired | full (a cap is used up) @param array<string, mixed> $link */
    public static function state(array $link, ?int $now = null): string
    {
        $now ??= time();
        if (($link['status'] ?? '') !== 'active') {
            return 'revoked';
        }
        if (!empty($link['expires_at']) && strtotime((string) $link['expires_at'] . ' UTC') <= $now) {
            return 'expired';
        }
        if ((int) $link['messages_used'] >= (int) $link['message_cap'] || (int) $link['tokens_used'] >= (int) $link['token_cap']) {
            return 'full';
        }

        return 'ok';
    }

    /** Records that the page was opened. Returns true when it was the FIRST time (the owner gets a push then). */
    public function recordOpen(int $id): bool
    {
        $link  = $this->byId($id);
        $first = $link !== null && empty($link['first_opened_at']);
        $now   = gmdate('Y-m-d H:i:s');
        $this->db->prepare('UPDATE recruiter_links SET open_count = open_count + 1, last_opened_at = :n, first_opened_at = COALESCE(first_opened_at, :n2) WHERE id = :id')
            ->execute([':n' => $now, ':n2' => $now, ':id' => $id]);

        return $first;
    }

    public function revoke(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE recruiter_links SET status = 'revoked' WHERE id = :id AND user_id = :u AND status = 'active'");
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** Changes caps / expiry / label of one of the user's links. Only the keys given change. @param array<string, mixed> $fields */
    public function update(int $userId, int $id, array $fields): bool
    {
        $set = [];
        $par = [':id' => $id, ':u' => $userId];
        if (isset($fields['message_cap'])) { $set[] = 'message_cap = :mc'; $par[':mc'] = max(1, (int) $fields['message_cap']); }
        if (isset($fields['token_cap']))   { $set[] = 'token_cap = :tc';   $par[':tc'] = max(1000, (int) $fields['token_cap']); }
        if (array_key_exists('expires_at', $fields)) { $set[] = 'expires_at = :e'; $par[':e'] = $fields['expires_at']; }
        if (isset($fields['label']) && trim((string) $fields['label']) !== '') { $set[] = 'label = :l'; $par[':l'] = mb_substr(trim((string) $fields['label']), 0, 160); }
        if (isset($fields['job_posting'])) { $set[] = 'job_posting = :p'; $par[':p'] = mb_substr((string) $fields['job_posting'], 0, 20000); }
        if (isset($fields['match_summary'])) { $set[] = 'match_summary = :m'; $par[':m'] = (string) $fields['match_summary']; }
        if (!empty($fields['reactivate'])) { $set[] = "status = 'active'"; }
        if ($set === []) {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE recruiter_links SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :u');
        $stmt->execute($par);

        return $stmt->rowCount() > 0 || $this->byId($id) !== null;
    }

    /** @return list<array<string, mixed>> a user's links, newest first (no job posting text) */
    public function list(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, token, label, company, role_title, status, expires_at, message_cap, token_cap, messages_used, tokens_used, open_count, first_opened_at, last_opened_at, created_at
             FROM recruiter_links WHERE user_id = :u ORDER BY id DESC'
        );
        $stmt->execute([':u' => $userId]);

        return $stmt->fetchAll();
    }

    /** Adds one chat message. @return int its id */
    public function addMessage(int $linkId, string $role, string $content, int $tokens = 0, ?string $ipHash = null): int
    {
        $this->db->prepare('INSERT INTO recruiter_messages (link_id, role, content, tokens, ip_hash, created_at) VALUES (:l, :r, :c, :t, :ip, :now)')
            ->execute([':l' => $linkId, ':r' => $role === 'assistant' ? 'assistant' : 'user', ':c' => mb_substr($content, 0, 8000), ':t' => max(0, $tokens), ':ip' => $ipHash, ':now' => gmdate('Y-m-d H:i:s')]);

        return (int) $this->db->lastInsertId();
    }

    /** What a chat turn cost: counts the recruiter's message and the tokens against the caps. */
    public function addUsage(int $linkId, int $tokens, int $messages = 1): void
    {
        $this->db->prepare('UPDATE recruiter_links SET messages_used = messages_used + :m, tokens_used = tokens_used + :t WHERE id = :id')
            ->execute([':m' => $messages, ':t' => max(0, $tokens), ':id' => $linkId]);
    }

    /** The conversation, oldest first (the last $limit messages). @return list<array{id:int, role:string, content:string, created_at:string}> */
    public function messages(int $linkId, int $limit = 40): array
    {
        $stmt = $this->db->prepare('SELECT id, role, content, created_at FROM recruiter_messages WHERE link_id = :l ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute([':l' => $linkId]);

        return array_reverse(array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'role' => (string) $r['role'], 'content' => (string) $r['content'], 'created_at' => (string) $r['created_at']], $stmt->fetchAll()));
    }

    /** How many recruiter messages came from this (hashed) address in the last $seconds — the per-IP rate limit. */
    public function recentFromIp(string $ipHash, int $seconds): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM recruiter_messages WHERE ip_hash = :ip AND role = 'user' AND created_at >= :t");
        $stmt->execute([':ip' => $ipHash, ':t' => gmdate('Y-m-d H:i:s', time() - $seconds)]);

        return (int) $stmt->fetchColumn();
    }

    /** Saves a message left for the owner BEFORE anything is sent. @return int the lead id */
    public function addLead(int $linkId, string $name, string $contact, string $message): int
    {
        $this->db->prepare('INSERT INTO recruiter_leads (link_id, name, contact, message, created_at) VALUES (:l, :n, :c, :m, :now)')
            ->execute([':l' => $linkId, ':n' => mb_substr(trim($name), 0, 120), ':c' => mb_substr(trim($contact), 0, 200), ':m' => mb_substr(trim($message), 0, 4000), ':now' => gmdate('Y-m-d H:i:s')]);

        return (int) $this->db->lastInsertId();
    }

    public function markLeadDelivery(int $leadId, ?bool $push, ?bool $email): void
    {
        $this->db->prepare('UPDATE recruiter_leads SET push_ok = :p, email_ok = :e WHERE id = :id')
            ->execute([':p' => $push === null ? null : (int) $push, ':e' => $email === null ? null : (int) $email, ':id' => $leadId]);
    }

    public function leadsToday(int $linkId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM recruiter_leads WHERE link_id = :l AND created_at >= :t');
        $stmt->execute([':l' => $linkId, ':t' => gmdate('Y-m-d H:i:s', time() - 86400)]);

        return (int) $stmt->fetchColumn();
    }

    /** The messages left for a user's links, newest first. @return list<array<string, mixed>> */
    public function leads(int $userId, bool $onlyUnread = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.id, l.name, l.contact, l.message, l.push_ok, l.email_ok, l.read_at, l.created_at, k.label AS link_label, k.company
             FROM recruiter_leads l JOIN recruiter_links k ON k.id = l.link_id WHERE k.user_id = :u' . ($onlyUnread ? ' AND l.read_at IS NULL' : '') . ' ORDER BY l.id DESC LIMIT 100'
        );
        $stmt->execute([':u' => $userId]);

        return $stmt->fetchAll();
    }

    /** Leads neither the push nor the email reached the owner with (the last 2 days) — for the retry job. @return list<array<string, mixed>> */
    public function undelivered(): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.id, l.link_id, l.name, l.contact, l.message FROM recruiter_leads l
             WHERE (l.push_ok IS NULL OR l.push_ok = 0) AND (l.email_ok IS NULL OR l.email_ok = 0) AND l.created_at >= :t ORDER BY l.id LIMIT 50'
        );
        $stmt->execute([':t' => gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);

        return $stmt->fetchAll();
    }

    public function markLeadsRead(int $userId): int
    {
        $stmt = $this->db->prepare('UPDATE recruiter_leads SET read_at = :n WHERE read_at IS NULL AND link_id IN (SELECT id FROM recruiter_links WHERE user_id = :u)');
        $stmt->execute([':n' => gmdate('Y-m-d H:i:s'), ':u' => $userId]);

        return $stmt->rowCount();
    }

    /** Deletes chat messages (and the unanswered-question notes) older than the retention (leads are kept). @return int how many chat messages */
    public function purge(?int $days = null): int
    {
        $before = gmdate('Y-m-d H:i:s', time() - ($days ?? self::RETENTION_DAYS) * 86400);
        $stmt = $this->db->prepare('DELETE FROM recruiter_messages WHERE created_at < :t');
        $stmt->execute([':t' => $before]);
        $n = $stmt->rowCount();
        try {
            $this->db->prepare('DELETE FROM recruiter_gaps WHERE created_at < :t')->execute([':t' => $before]);
        } catch (\Throwable) {
            // the gaps table is not migrated yet: nothing to purge
        }

        return $n;
    }

    /**
     * Notes that the facts could not answer a recruiter's question (the lookup found nothing). Best effort: a missing table never breaks the chat, and the same question
     * twice in a row on one link is noted once.
     */
    public function addGap(int $linkId, string $question, string $topic, string $tool): void
    {
        try {
            $q = mb_substr(trim($question), 0, 1500);
            $last = $this->db->prepare('SELECT question FROM recruiter_gaps WHERE link_id = :l ORDER BY id DESC LIMIT 1');
            $last->execute([':l' => $linkId]);
            if ($q === '' || $last->fetchColumn() === $q) {
                return;
            }
            $this->db->prepare('INSERT INTO recruiter_gaps (link_id, question, topic, tool, created_at) VALUES (:l, :q, :t, :tool, :now)')
                ->execute([':l' => $linkId, ':q' => $q, ':t' => mb_substr(trim($topic), 0, 160), ':tool' => mb_substr($tool, 0, 30), ':now' => gmdate('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            error_log('recruiter gap: ' . $e->getMessage());
        }
    }

    /** The questions nothing answered, newest first. @return list<array<string, mixed>> */
    public function gaps(int $userId, int $limit = 50): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT g.id, g.question, g.topic, g.created_at, k.label AS link_label FROM recruiter_gaps g JOIN recruiter_links k ON k.id = g.link_id
                 WHERE k.user_id = :u AND g.dismissed_at IS NULL ORDER BY g.id DESC LIMIT ' . max(1, min(200, $limit))
            );
            $stmt->execute([':u' => $userId]);

            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];   // not migrated yet
        }
    }

    /** Marks one (or all) of the user's unanswered questions as dealt with. */
    public function dismissGap(int $userId, ?int $id = null): int
    {
        try {
            $sql = 'UPDATE recruiter_gaps SET dismissed_at = :n WHERE dismissed_at IS NULL AND link_id IN (SELECT id FROM recruiter_links WHERE user_id = :u)' . ($id !== null ? ' AND id = :id' : '');
            $par = [':n' => gmdate('Y-m-d H:i:s'), ':u' => $userId] + ($id !== null ? [':id' => $id] : []);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($par);

            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function envInt(string $key, int $default): int
    {
        $v = $_ENV[$key] ?? getenv($key);

        return is_string($v) && ctype_digit(trim($v)) && (int) trim($v) > 0 ? (int) trim($v) : $default;
    }
}
