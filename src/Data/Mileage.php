<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Driving / mileage (kørsel) tracking. Each row is one driving DAY to the customer.
 *
 * The 60-day rule: the first 60 driving days to the same workplace within a rolling 12
 * months are erhvervsmæssig kørsel (business) — deducted at statens takst (two-tier at
 * 20,000 km/year), a BUSINESS deduction that lowers profit + the tax reserve. Day 61+
 * becomes commuting to a fast arbejdssted → befordringsfradrag (25–120 km/day at one
 * rate, above at another; the first 24 km/day are not deductible) — a PERSONAL tax-return
 * deduction, estimated separately and NEVER folded into the business P&L. No moms, no
 * cash movement. Rates/distance come from UserSettings (they change yearly).
 */
final class Mileage
{
    public const LOCAL_TZ = 'Europe/Copenhagen';

    public const BUSINESS_DAY_LIMIT = 60;     // days in trailing 12 months before it's commuting
    public const YEAR_KM_TIER       = 20000;  // statens takst high-rate ceiling per year
    public const COMMUTE_FREE_KM    = 24;     // befordringsfradrag: first 24 km/day not deductible
    public const COMMUTE_BAND_KM    = 120;    // befordringsfradrag: rate step at 120 km/day

    private PDO $db;

    public function __construct(private UserSettings $settings, ?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TZ)))->format('Y-m-d');
    }

    /** Logs a driving day. km defaults to the configured round-trip distance. Returns id. */
    public function logTrip(int $userId, ?string $date = null, ?float $km = null, ?string $note = null): int
    {
        $date = $date !== null && trim($date) !== '' ? date('Y-m-d', strtotime($date) ?: time()) : self::today();
        if ($km === null || $km <= 0) {
            $km = $this->settings->mileageConfig($userId)['round_trip'];
        }
        $note = $note !== null ? mb_substr(trim($note), 0, 255) : null;

        $stmt = $this->db->prepare(
            'INSERT INTO mileage_trips (user_id, trip_date, km, note) VALUES (:u, :d, :k, :n)'
        );
        $stmt->execute([':u' => $userId, ':d' => $date, ':k' => round(max(0.0, $km), 2), ':n' => $note]);

        return (int) $this->db->lastInsertId();
    }

    public function deleteTrip(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM mileage_trips WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Classifies every logged trip (chronological) into business vs commuter and computes
     * each one's deductible amount. The single source of truth for all the aggregates.
     *
     * @return array<int, array{id:int, date:string, km:float, note:string, bucket:string,
     *   business_km:float, business_amount:float, commuter_amount:float, rolling_count:int}>
     */
    public function classify(int $userId): array
    {
        $cfg = $this->settings->mileageConfig($userId);

        $stmt = $this->db->prepare(
            'SELECT id, trip_date, km, note FROM mileage_trips WHERE user_id = :u ORDER BY trip_date ASC, id ASC'
        );
        $stmt->execute([':u' => $userId]);
        $trips = $stmt->fetchAll();

        // Trailing-12-month day count is done against the ordered date list.
        $dates = array_map(static fn (array $t): int => (int) strtotime((string) $t['trip_date']), $trips);

        $yearBizKm = [];   // 'YYYY' => cumulative business km that year (for the 20k tier)
        $out = [];
        foreach ($trips as $i => $t) {
            $ts    = $dates[$i];
            $floor = strtotime('-1 year +1 day', $ts) ?: $ts;   // trailing 12-month window start
            $count = 0;
            foreach ($dates as $d) {
                if ($d <= $ts && $d >= $floor) {
                    $count++;
                }
            }

            $km       = round((float) $t['km'], 2);
            $year     = substr((string) $t['trip_date'], 0, 4);
            $business = $count <= self::BUSINESS_DAY_LIMIT;
            $bizKm    = 0.0; $bizAmt = 0.0; $comAmt = 0.0;

            if ($business) {
                $bizKm  = $km;
                $prior  = $yearBizKm[$year] ?? 0.0;
                $highKm = max(0.0, min($km, self::YEAR_KM_TIER - $prior));
                $lowKm  = $km - $highKm;
                $bizAmt = $highKm * $cfg['rate_high'] + $lowKm * $cfg['rate_low'];
                $yearBizKm[$year] = $prior + $km;
            } else {
                // Befordringsfradrag on the day's (round-trip) km.
                $band1  = max(0.0, min($km, (float) self::COMMUTE_BAND_KM) - self::COMMUTE_FREE_KM);
                $band2  = max(0.0, $km - self::COMMUTE_BAND_KM);
                $comAmt = $band1 * $cfg['commute'] + $band2 * $cfg['commute_far'];
            }

            $out[] = [
                'id'              => (int) $t['id'],
                'date'            => (string) $t['trip_date'],
                'km'              => $km,
                'note'            => $t['note'] !== null ? (string) $t['note'] : '',
                'bucket'          => $business ? 'business' : 'commuter',
                'business_km'     => $bizKm,
                'business_amount' => round($bizAmt, 2),
                'commuter_amount' => round($comAmt, 2),
                'rolling_count'   => $count,
            ];
        }

        return $out;
    }

    /**
     * Business (erhvervsmæssig) deduction for trips in a period — for the P&L / tax
     * reserve. All-time when no range. Commuter driving is deliberately excluded (it's a
     * personal deduction, not a business cost).
     */
    public function businessDeduction(int $userId, ?string $from = null, ?string $to = null): float
    {
        $sum = 0.0;
        foreach ($this->classify($userId) as $t) {
            if ($t['bucket'] !== 'business') {
                continue;
            }
            if ($from !== null && $t['date'] < $from) {
                continue;
            }
            if ($to !== null && $t['date'] > $to) {
                continue;
            }
            $sum += $t['business_amount'];
        }

        return round($sum, 2);
    }

    /**
     * The mileage card (kind: mileage) for a year at $offset (0 = current, -1 = last).
     *
     * @return array<string, mixed>
     */
    public function card(int $userId, int $offset = 0): array
    {
        $cfg  = $this->settings->mileageConfig($userId);
        $tz   = new DateTimeZone(self::LOCAL_TZ);
        $year = (int) (new DateTimeImmutable('now', $tz))->format('Y') + $offset;
        $rows = $this->classify($userId);

        $bizDays = 0; $bizKm = 0.0; $bizAmt = 0.0;
        $comDays = 0; $comKm = 0.0; $comAmt = 0.0;
        $trips = [];
        foreach ($rows as $r) {
            if ((int) substr($r['date'], 0, 4) !== $year) {
                continue;
            }
            if ($r['bucket'] === 'business') {
                $bizDays++; $bizKm += $r['km']; $bizAmt += $r['business_amount'];
            } else {
                $comDays++; $comKm += $r['km']; $comAmt += $r['commuter_amount'];
            }
            $trips[] = [
                'id'     => $r['id'], 'date' => $r['date'], 'km' => $r['km'],
                'note'   => $r['note'], 'bucket' => $r['bucket'],
                'amount' => $r['bucket'] === 'business' ? $r['business_amount'] : $r['commuter_amount'],
            ];
        }
        usort($trips, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        // Rolling counter as of today: driving days in the trailing 12 months.
        $todayTs = strtotime(self::today()) ?: time();
        $floor   = strtotime('-1 year +1 day', $todayTs) ?: $todayTs;
        $logged12 = 0;
        foreach ($rows as $r) {
            $d = strtotime($r['date']) ?: 0;
            if ($d <= $todayTs && $d >= $floor) {
                $logged12++;
            }
        }

        return [
            'kind'         => 'mileage',
            'title'        => 'Mileage · ' . $year,
            'currency'     => 'DKK',
            'offset'       => $offset,
            'year'         => $year,
            'can_next'     => $offset < 0,
            'period_label' => (string) $year,
            'round_trip'   => $cfg['round_trip'],
            'rates'        => $cfg,
            'business'     => ['days' => $bizDays, 'km' => round($bizKm, 2), 'amount' => round($bizAmt, 2)],
            'commuter'     => ['days' => $comDays, 'km' => round($comKm, 2), 'amount' => round($comAmt, 2)],
            'counter'      => [
                'limit'         => self::BUSINESS_DAY_LIMIT,
                'logged_12mo'   => $logged12,
                'business_used' => min($logged12, self::BUSINESS_DAY_LIMIT),
                'remaining'     => max(0, self::BUSINESS_DAY_LIMIT - $logged12),
                'commuting_now' => $logged12 >= self::BUSINESS_DAY_LIMIT,
            ],
            'trips'        => array_slice($trips, 0, 60),
        ];
    }
}
