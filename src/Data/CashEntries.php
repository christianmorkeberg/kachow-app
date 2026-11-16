<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Manual bank-account movements that aren't already captured as invoices, expenses or
 * owner draws: a moms payment to SKAT, a bank fee, money the owner injects, etc. Feeds
 * the cash / expected-balance view. Owner-scoped. Not part of the legal bogføring, so
 * these are freely editable/deletable (no audit trail).
 */
final class CashEntries
{
    public const LOCAL_TZ = 'Europe/Copenhagen';

    /** Loose categories (label only; the direction carries the sign). */
    public const CATEGORIES = ['moms', 'tax', 'fee', 'deposit', 'other'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TZ)))->format('Y-m-d');
    }

    /** Snaps a free category to a known one, else 'other'. */
    public static function normalizeCategory(?string $c): string
    {
        $c = strtolower(trim((string) $c));

        return in_array($c, self::CATEGORIES, true) ? $c : 'other';
    }

    /**
     * Records a movement. $direction 'in' (money into the account) or 'out'. Returns id.
     */
    public function add(int $userId, string $direction, float $amount, string $category = 'other', ?string $note = null, ?string $date = null): int
    {
        $dir  = $direction === 'in' ? 'in' : 'out';
        $date = $date !== null && trim($date) !== '' ? date('Y-m-d', strtotime($date) ?: time()) : self::today();
        $note = $note !== null ? mb_substr(trim($note), 0, 255) : null;

        $stmt = $this->db->prepare(
            'INSERT INTO cash_entries (user_id, occurred_at, direction, amount, category, note)
             VALUES (:u, :d, :dir, :a, :c, :n)'
        );
        $stmt->execute([
            ':u'   => $userId,
            ':d'   => $date,
            ':dir' => $dir,
            ':a'   => round(abs($amount), 2),
            ':c'   => self::normalizeCategory($category),
            ':n'   => $note,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** One movement, owner-scoped (null when it is not theirs / does not exist). @return array{id:int, date:string, direction:string, amount:float, category:string, note:string}|null */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, occurred_at, direction, amount, category, note FROM cash_entries WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }

        return [
            'id' => (int) $r['id'], 'date' => (string) $r['occurred_at'], 'direction' => (string) $r['direction'],
            'amount' => round((float) $r['amount'], 2), 'category' => (string) $r['category'], 'note' => $r['note'] !== null ? (string) $r['note'] : '',
        ];
    }

    /**
     * Corrects a movement: only the fields given change. Returns the movement as it is now, or null when it is not theirs.
     *
     * @param array<string, mixed> $fields direction, amount, category, note, date
     * @return array{id:int, date:string, direction:string, amount:float, category:string, note:string}|null
     */
    public function update(int $userId, int $id, array $fields): ?array
    {
        $cur = $this->get($userId, $id);
        if ($cur === null) {
            return null;
        }
        $set = [];
        $par = [':id' => $id, ':u' => $userId];
        if (isset($fields['direction']) && in_array($fields['direction'], ['in', 'out'], true)) {
            $set[] = 'direction = :dir';
            $par[':dir'] = $fields['direction'];
        }
        if (isset($fields['amount']) && is_numeric($fields['amount']) && (float) $fields['amount'] > 0) {
            $set[] = 'amount = :a';
            $par[':a'] = round(abs((float) $fields['amount']), 2);
        }
        if (isset($fields['category']) && trim((string) $fields['category']) !== '') {
            $set[] = 'category = :c';
            $par[':c'] = self::normalizeCategory((string) $fields['category']);
        }
        if (array_key_exists('note', $fields) && $fields['note'] !== null) {
            $set[] = 'note = :n';
            $par[':n'] = mb_substr(trim((string) $fields['note']), 0, 255);
        }
        if (isset($fields['date']) && trim((string) $fields['date']) !== '' && ($t = strtotime((string) $fields['date'])) !== false) {
            $set[] = 'occurred_at = :d';
            $par[':d'] = date('Y-m-d', $t);
        }
        if ($set !== []) {
            $this->db->prepare('UPDATE cash_entries SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :u')->execute($par);
        }

        return $this->get($userId, $id);
    }

    /** Deletes a movement, owner-scoped. Returns true if a row was removed. */
    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM cash_entries WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Signed net of manual movements (in − out) for a category, or all categories when
     * null. For moms/tax "settled so far" figures. DKK assumed.
     */
    public function net(int $userId, ?string $category = null): float
    {
        $where  = ['user_id = :u'];
        $params = [':u' => $userId];
        if ($category !== null) {
            $where[]      = 'category = :c';
            $params[':c'] = self::normalizeCategory($category);
        }
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0)
             FROM cash_entries WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Gross manual money in / out (each a positive sum) across all categories, DKK.
     *
     * @return array{in:float, out:float}
     */
    public function grossTotals(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN direction='in'  THEN amount ELSE 0 END),0) AS cin,
                COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) AS cout
             FROM cash_entries WHERE user_id = :u"
        );
        $stmt->execute([':u' => $userId]);
        $r = $stmt->fetch();

        return ['in' => round((float) ($r['cin'] ?? 0), 2), 'out' => round((float) ($r['cout'] ?? 0), 2)];
    }

    /**
     * Recent movements, newest first.
     *
     * @return array<int, array{id:int, date:string, direction:string, amount:float, category:string, note:string}>
     */
    public function recent(int $userId, int $limit = 40): array
    {
        $limit = max(1, min(200, $limit));
        $stmt  = $this->db->prepare(
            'SELECT id, occurred_at, direction, amount, category, note FROM cash_entries
             WHERE user_id = :u ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'        => (int) $r['id'],
                'date'      => (string) $r['occurred_at'],
                'direction' => (string) $r['direction'],
                'amount'    => round((float) $r['amount'], 2),
                'category'  => (string) $r['category'],
                'note'      => $r['note'] !== null ? (string) $r['note'] : '',
            ];
        }

        return $out;
    }
}
