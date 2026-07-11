<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Per-user work log: free-text "what I did" entries, tagged with the job (DTU/DSB,
 * taken from the "Arbejde" calendar) and optional hours the user states. Distinct
 * from work_events (clock in/out). All dates are local (Europe/Copenhagen) YYYY-MM-DD.
 */
final class WorkLog
{
    public const LOCAL_TZ = 'Europe/Copenhagen';
    public const WORK_CALENDAR = 'Arbejde';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** The job label from an "Arbejde" event title = its first word (e.g. "DSB hjemme" → "DSB"). */
    public static function jobFromTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $title) ?: [$title];

        return (string) $parts[0];
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TZ)))->format('Y-m-d');
    }

    public function add(int $userId, string $date, string $job, ?float $hours, string $description): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO work_log (user_id, log_date, job, hours, description)
             VALUES (:u, :d, :j, :h, :desc)'
        );
        $stmt->execute([
            ':u'    => $userId,
            ':d'    => $date,
            ':j'    => mb_substr(trim($job), 0, 64),
            ':h'    => $hours,
            ':desc' => trim($description),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM work_log WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Entries in a date range (inclusive), newest first, optionally one job.
     *
     * @return array<int, array{id:int, date:string, job:string, hours:?float, description:string}>
     */
    public function listForUser(int $userId, string $from, string $to, ?string $job = null): array
    {
        $sql    = 'SELECT id, log_date, job, hours, description FROM work_log
                   WHERE user_id = :u AND log_date BETWEEN :from AND :to';
        $params = [':u' => $userId, ':from' => $from, ':to' => $to];
        if ($job !== null && $job !== '') {
            $sql .= ' AND LOWER(job) = LOWER(:job)';
            $params[':job'] = $job;
        }
        $sql .= ' ORDER BY log_date DESC, id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'          => (int) $r['id'],
                'date'        => (string) $r['log_date'],
                'job'         => (string) $r['job'],
                'hours'       => $r['hours'] !== null ? (float) $r['hours'] : null,
                'description' => (string) $r['description'],
            ];
        }

        return $out;
    }

    /** Distinct jobs already logged on a given date (to avoid re-nudging). */
    public function loggedJobsForDate(int $userId, string $date): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT job FROM work_log WHERE user_id = :u AND log_date = :d'
        );
        $stmt->execute([':u' => $userId, ':d' => $date]);

        return array_map('strval', array_column($stmt->fetchAll(), 'job'));
    }

    /**
     * Summary for a range: per-job hour totals + entry count, plus the entries.
     *
     * @return array{from:string, to:string, count:int, total_hours:float,
     *   by_job:array<int,array{job:string, hours:float, entries:int}>,
     *   items:array<int,array{id:int, date:string, job:string, hours:?float, description:string}>}
     */
    public function summary(int $userId, string $from, string $to, ?string $job = null): array
    {
        $items    = $this->listForUser($userId, $from, $to, $job);
        $byJob    = [];
        $total    = 0.0;
        foreach ($items as $it) {
            $j = $it['job'];
            if (!isset($byJob[$j])) {
                $byJob[$j] = ['job' => $j, 'hours' => 0.0, 'entries' => 0];
            }
            $byJob[$j]['entries']++;
            if ($it['hours'] !== null) {
                $byJob[$j]['hours'] += $it['hours'];
                $total += $it['hours'];
            }
        }

        return [
            'from'        => $from,
            'to'          => $to,
            'count'       => count($items),
            'total_hours' => round($total, 2),
            'by_job'      => array_values($byJob),
            'items'       => $items,
        ];
    }

    /**
     * Renderable card (kind = work_log) for a range summary.
     *
     * @return array<string, mixed>
     */
    public function card(int $userId, string $from, string $to, string $title, ?string $job = null): array
    {
        $s = $this->summary($userId, $from, $to, $job);

        return [
            'kind'        => 'work_log',
            'title'       => $title,
            'total_hours' => $s['total_hours'],
            'by_job'      => $s['by_job'],
            'items'       => $s['items'],
        ];
    }

    /**
     * Resolve a named period to [from, to, label] local dates.
     *
     * @return array{0:string, 1:string, 2:string}
     */
    public static function resolveRange(string $period, ?string $from, ?string $to): array
    {
        $tz  = new DateTimeZone(self::LOCAL_TZ);
        $now = new DateTimeImmutable('now', $tz);

        if ($from !== null && $from !== '') {
            $t = ($to !== null && $to !== '') ? $to : $now->format('Y-m-d');
            return [$from, $t, $from . ' – ' . $t];
        }

        return match ($period) {
            'today'      => [$now->format('Y-m-d'), $now->format('Y-m-d'), 'Today'],
            'yesterday'  => [$now->modify('-1 day')->format('Y-m-d'), $now->modify('-1 day')->format('Y-m-d'), 'Yesterday'],
            'last_week'  => [
                $now->modify('monday last week')->format('Y-m-d'),
                $now->modify('sunday last week')->format('Y-m-d'),
                'Last week',
            ],
            'this_month' => [$now->format('Y-m-01'), $now->format('Y-m-d'), 'This month'],
            'last_month' => [
                $now->modify('first day of last month')->format('Y-m-d'),
                $now->modify('last day of last month')->format('Y-m-d'),
                'Last month',
            ],
            'this_year'  => [$now->format('Y-01-01'), $now->format('Y-m-d'), 'This year'],
            'all'        => ['2000-01-01', $now->format('Y-m-d'), 'All time'],
            default      => [
                $now->modify('monday this week')->format('Y-m-d'),
                $now->format('Y-m-d'),
                'This week',
            ],
        };
    }
}
