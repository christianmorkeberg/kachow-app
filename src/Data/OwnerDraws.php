<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Owner drawings (privat hævning) for an enkeltmandsvirksomhed — money the owner
 * pays themselves out of the business. This is NOT a deductible salary/expense and
 * does NOT touch profit or moms; it is a pure equity/cash movement, kept here so the
 * overview/reserve can show real free cash (business cash minus what's really the
 * owner's tax + moms). Owner-scoped.
 */
final class OwnerDraws
{
    private const LOCAL_TZ = 'Europe/Copenhagen';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Local (Europe/Copenhagen) date 'Y-m-d' for today. */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TZ)))->format('Y-m-d');
    }

    /**
     * Records a drawing. Returns the new id.
     */
    public function add(int $userId, float $amount, ?string $date = null, string $currency = 'DKK', ?string $note = null): int
    {
        $date = $date !== null && trim($date) !== '' ? date('Y-m-d', strtotime($date) ?: time()) : self::today();
        $cur  = strtoupper(mb_substr(trim($currency), 0, 3)) ?: 'DKK';
        $note = $note !== null ? mb_substr(trim($note), 0, 255) : null;

        $stmt = $this->db->prepare(
            'INSERT INTO owner_draws (user_id, drawn_at, amount, currency, note)
             VALUES (:u, :d, :a, :c, :n)'
        );
        $stmt->execute([':u' => $userId, ':d' => $date, ':a' => round($amount, 2), ':c' => $cur, ':n' => $note]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM owner_draws WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM owner_draws WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Draws in a date range (inclusive 'Y-m-d' or null), newest first, with a total
     * per currency (never blended).
     *
     * @return array{
     *   items:array<int,array{id:int,date:string,amount:float,currency:string,note:string}>,
     *   count:int,
     *   totals:array<int,array{currency:string,total:float,count:int}>
     * }
     */
    public function summary(int $userId, ?string $from = null, ?string $to = null): array
    {
        $where  = ['user_id = :u'];
        $params = [':u' => $userId];
        if ($from !== null) {
            $where[]        = 'drawn_at >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $where[]      = 'drawn_at <= :to';
            $params[':to'] = $to;
        }

        $stmt = $this->db->prepare(
            'SELECT id, drawn_at, amount, currency, note FROM owner_draws
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY drawn_at DESC, id DESC LIMIT 300'
        );
        $stmt->execute($params);

        $items  = [];
        $byCur  = [];
        foreach ($stmt->fetchAll() as $r) {
            $amt = (float) $r['amount'];
            $cur = (string) ($r['currency'] ?? 'DKK') ?: 'DKK';
            $byCur[$cur] ??= ['total' => 0.0, 'count' => 0];
            $byCur[$cur]['total'] += $amt;
            $byCur[$cur]['count']++;
            $items[] = [
                'id'       => (int) $r['id'],
                'date'     => (string) $r['drawn_at'],
                'amount'   => $amt,
                'currency' => $cur,
                'note'     => $r['note'] !== null ? (string) $r['note'] : '',
            ];
        }

        $totals = [];
        foreach ($byCur as $cur => $agg) {
            $totals[] = ['currency' => $cur, 'total' => round($agg['total'], 2), 'count' => $agg['count']];
        }
        usort($totals, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return ['items' => $items, 'count' => count($items), 'totals' => $totals];
    }

    /** Total drawn in DKK over a range (for the reserve/overview). */
    public function totalDkk(int $userId, ?string $from = null, ?string $to = null): float
    {
        $s = $this->summary($userId, $from, $to);
        foreach ($s['totals'] as $t) {
            if ($t['currency'] === 'DKK') {
                return $t['total'];
            }
        }

        return 0.0;
    }

    /**
     * Renderable card (kind = owner_draws) for a period's drawings.
     *
     * @return array<string, mixed>
     */
    public function card(int $userId, ?string $from, ?string $to, string $title): array
    {
        $s = $this->summary($userId, $from, $to);

        return [
            'kind'   => 'owner_draws',
            'title'  => $title,
            'totals' => $s['totals'],
            'count'  => $s['count'],
            'items'  => $s['items'],
        ];
    }
}
