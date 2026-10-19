<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * The developer's log of voice (Gemini Live) sessions: one row per event, owner-scoped. Also what the
 * daily-minutes cap is computed from — a session lasts from its first event to its last, so a tab that was
 * just closed (no explicit end) still counts.
 */
final class VoiceLog
{
    public const KINDS = [
        'session_start', 'setup_complete', 'first_audio', 'tool_call', 'tool_error', 'interrupted', 'cancelled',
        'goaway', 'resume', 'idle_close', 'limit_close', 'session_end', 'client_error', 'auto_report', 'gateway',
        'confirm_ask', 'confirm_done',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** @param array<string, mixed>|null $detail */
    public function add(int $userId, string $sessionId, string $kind, ?int $conversationId = null, ?string $name = null, ?bool $ok = null, ?int $ms = null, ?array $detail = null): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            return;
        }
        $json = $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->prepare(
            'INSERT INTO voice_events (user_id, session_id, conversation_id, kind, name, ok, ms, detail, created_at)
             VALUES (:u, :s, :c, :k, :n, :ok, :ms, :d, :at)'
        )->execute([
            ':u' => $userId, ':s' => $sessionId, ':c' => $conversationId, ':k' => $kind,
            ':n' => $name !== null ? mb_substr($name, 0, 64) : null,
            ':ok' => $ok === null ? null : ($ok ? 1 : 0), ':ms' => $ms,
            ':d' => $json !== null ? mb_substr((string) $json, 0, 6000) : null,
            ':at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** The session's conversation id if this session belongs to the user, else null (also the ownership check). */
    public function sessionConversation(int $userId, string $sessionId): ?int
    {
        $stmt = $this->db->prepare("SELECT conversation_id FROM voice_events WHERE user_id = :u AND session_id = :s AND kind = 'session_start' LIMIT 1");
        $stmt->execute([':u' => $userId, ':s' => $sessionId]);
        $r = $stmt->fetch();

        return $r === false ? null : (int) ($r['conversation_id'] ?? 0);
    }

    public function ownsSession(int $userId, string $sessionId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM voice_events WHERE user_id = :u AND session_id = :s AND kind = 'session_start' LIMIT 1");
        $stmt->execute([':u' => $userId, ':s' => $sessionId]);

        return $stmt->fetchColumn() !== false;
    }

    /** How many events of this kind a session already has (for throttling auto-reports). */
    public function countKind(string $sessionId, string $kind): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM voice_events WHERE session_id = :s AND kind = :k');
        $stmt->execute([':s' => $sessionId, ':k' => $kind]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The latest confirm_ask / confirm_done for this exact call (hash), or null.
     *
     * @return array{kind:string, turn:int, at:int}|null
     */
    public function latestConfirm(string $sessionId, string $hash): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT kind, detail, created_at FROM voice_events WHERE session_id = :s AND kind IN ('confirm_ask','confirm_done') ORDER BY id DESC LIMIT 20"
        );
        $stmt->execute([':s' => $sessionId]);
        foreach ($stmt->fetchAll() as $r) {
            $d = json_decode((string) $r['detail'], true);
            if (is_array($d) && ($d['h'] ?? '') === $hash) {
                return ['kind' => (string) $r['kind'], 'turn' => (int) ($d['turn'] ?? 0), 'at' => (int) strtotime((string) $r['created_at'] . ' UTC')];
            }
        }

        return null;
    }

    /** The conversation holding a session's "ask the full assistant" turns, if one was started. */
    public function gatewayConversation(string $sessionId): ?int
    {
        $stmt = $this->db->prepare("SELECT conversation_id FROM voice_events WHERE session_id = :s AND kind = 'gateway' ORDER BY id DESC LIMIT 1");
        $stmt->execute([':s' => $sessionId]);
        $v = $stmt->fetchColumn();

        return $v === false || $v === null ? null : (int) $v;
    }

    /**
     * Seconds of voice used today (Copenhagen day), over every session that started today.
     */
    public function usedSecondsToday(int $userId, ?DateTimeImmutable $now = null): int
    {
        $tz     = new DateTimeZone('Europe/Copenhagen');
        $utc    = new DateTimeZone('UTC');
        $now    = $now ?? new DateTimeImmutable('now', $utc);
        $dayUtc = $now->setTimezone($tz)->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'SELECT session_id, MIN(created_at) AS first_at, MAX(created_at) AS last_at, SUM(CASE WHEN kind = \'session_start\' THEN 1 ELSE 0 END) AS starts
             FROM voice_events WHERE user_id = :u AND created_at >= :d GROUP BY session_id'
        );
        $stmt->execute([':u' => $userId, ':d' => $dayUtc]);
        $total = 0;
        foreach ($stmt->fetchAll() as $r) {
            if ((int) $r['starts'] < 1) {
                continue;
            }
            $total += max(0, (int) strtotime((string) $r['last_at'] . ' UTC') - (int) strtotime((string) $r['first_at'] . ' UTC'));
        }

        return $total;
    }

    /**
     * The last sessions, with what happened: tool calls, errors, interruptions, how long, time to first audio —
     * for the developer ("how did the last voice session go?").
     *
     * @return list<array<string, mixed>>
     */
    public function recentSessions(int $userId, int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            "SELECT session_id FROM voice_events WHERE user_id = :u AND kind = 'session_start' ORDER BY id DESC LIMIT " . max(1, min(20, $limit))
        );
        $stmt->execute([':u' => $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $s) {
            $ev = $this->db->prepare('SELECT kind, name, ok, ms, detail, created_at FROM voice_events WHERE session_id = :s ORDER BY id ASC');
            $ev->execute([':s' => $s['session_id']]);
            $rows = $ev->fetchAll();
            if ($rows === []) {
                continue;
            }
            $tools = $errors = $interrupts = $cancels = 0;
            $firstAudio = null;
            $lines = [];
            foreach ($rows as $r) {
                $d = $r['detail'] !== null ? json_decode((string) $r['detail'], true) : null;
                switch ($r['kind']) {
                    case 'tool_call':
                        $tools++;
                        $lines[] = ['tool', (string) $r['name'], (int) $r['ms'], (string) ($d['args'] ?? '')];
                        break;
                    case 'tool_error':
                        $tools++; $errors++;
                        $lines[] = ['ERROR', (string) $r['name'], (int) $r['ms'], (string) ($d['error'] ?? '')];
                        break;
                    case 'client_error':
                        $errors++;
                        $lines[] = ['ERROR', 'client', 0, (string) ($d['message'] ?? '')];
                        break;
                    case 'interrupted': $interrupts++; break;
                    case 'cancelled':   $cancels++; break;
                    case 'first_audio': $firstAudio ??= (int) $r['ms']; break;
                }
            }
            $first = strtotime((string) $rows[0]['created_at'] . ' UTC');
            $last  = strtotime((string) $rows[count($rows) - 1]['created_at'] . ' UTC');
            $local = (new DateTimeImmutable('@' . $first))->setTimezone(new DateTimeZone('Europe/Copenhagen'))->format('D j M H:i');
            $out[] = [
                'session'          => substr((string) $s['session_id'], 0, 8),
                'started'          => $local,
                'seconds'          => max(0, $last - $first),
                'tool_calls'       => $tools,
                'errors'           => $errors,
                'interruptions'    => $interrupts,
                'cancelled_calls'  => $cancels,
                'first_audio_ms'   => $firstAudio,
                'ended_by'         => self::endedBy($rows),
                'notable'          => array_slice($lines, -12),
            ];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function endedBy(array $rows): string
    {
        foreach (array_reverse($rows) as $r) {
            if (in_array($r['kind'], ['idle_close', 'limit_close', 'session_end'], true)) {
                return (string) $r['kind'];
            }
        }

        return 'unknown';
    }
}
