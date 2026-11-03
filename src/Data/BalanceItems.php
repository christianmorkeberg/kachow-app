<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/** Hand-entered balance-sheet items (equipment, loans, a stated bank balance), valid from a date until replaced. Owner-scoped. */
final class BalanceItems
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public function set(int $userId, string $kind, string $label, float $amount, string $asOf, ?string $note = null): void
    {
        $kind  = $kind === 'liability' ? 'liability' : 'asset';
        $label = mb_substr(trim($label), 0, 80);
        $this->db->prepare('DELETE FROM balance_items WHERE user_id = :u AND kind = :k AND label = :l AND as_of = :d')
            ->execute([':u' => $userId, ':k' => $kind, ':l' => $label, ':d' => $asOf]);
        $this->db->prepare('INSERT INTO balance_items (user_id, kind, label, amount, as_of, note) VALUES (:u, :k, :l, :a, :d, :n)')
            ->execute([':u' => $userId, ':k' => $kind, ':l' => $label, ':a' => round($amount, 2), ':d' => $asOf, ':n' => $note !== null ? mb_substr($note, 0, 255) : null]);
    }

    /**
     * The items in force on a date: per (kind, label) the latest row with as_of <= date; amount 0 means retired and is left out.
     *
     * @return list<array{kind:string, label:string, amount:float, as_of:string, note:string}>
     */
    public function inForce(int $userId, string $date): array
    {
        $stmt = $this->db->prepare('SELECT kind, label, amount, as_of, note FROM balance_items WHERE user_id = :u AND as_of <= :d ORDER BY as_of ASC, id ASC');
        $stmt->execute([':u' => $userId, ':d' => $date]);
        $latest = [];
        foreach ($stmt->fetchAll() as $r) {
            $latest[$r['kind'] . '|' . mb_strtolower((string) $r['label'])] = [
                'kind' => (string) $r['kind'], 'label' => (string) $r['label'], 'amount' => round((float) $r['amount'], 2),
                'as_of' => (string) $r['as_of'], 'note' => (string) ($r['note'] ?? ''),
            ];
        }

        return array_values(array_filter($latest, static fn (array $i): bool => $i['amount'] != 0.0));
    }
}
