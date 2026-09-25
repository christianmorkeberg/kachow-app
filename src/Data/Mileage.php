<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Driving / mileage (kørsel) tracking. Each row is one driving DAY to a destination.
 *
 * Destinations carry the tax treatment:
 *   - type='business' (a customer, e.g. Kachow Consult): the first 60 driving days to
 *     THAT destination within a rolling 12 months are erhvervsmæssig kørsel (business),
 *     deducted at statens takst (two-tier at 20,000 km/year, global per person per year)
 *     — a BUSINESS deduction that lowers profit + the tax reserve. Day 61+ to the same
 *     destination becomes commuting to a fast arbejdssted → befordringsfradrag.
 *   - type='commute' (a fixed/regular workplace, e.g. DTU): every day is befordringsfradrag
 *     from day 1 — a PERSONAL-return deduction, estimated separately and NEVER folded into
 *     the business P&L.
 *
 * befordringsfradrag: 25–120 km/day at one rate, above at another; the first 24 km/day are
 * not deductible. No moms, no cash movement. Rates come from UserSettings (yearly); each
 * destination has its own round-trip distance.
 */
final class Mileage
{
    public const LOCAL_TZ = 'Europe/Copenhagen';

    public const BUSINESS_DAY_LIMIT = 60;     // days in trailing 12 months (per destination) before commuting
    public const YEAR_KM_TIER       = 20000;  // statens takst high-rate ceiling per year (global)
    public const COMMUTE_FREE_KM    = 24;     // befordringsfradrag: first 24 km/day not deductible
    public const COMMUTE_BAND_KM    = 120;    // befordringsfradrag: rate step at 120 km/day

    public const TYPE_BUSINESS = 'business';
    public const TYPE_COMMUTE  = 'commute';

    private PDO $db;

    public function __construct(private UserSettings $settings, ?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TZ)))->format('Y-m-d');
    }

    // ---- Destinations ------------------------------------------------------

    /**
     * The user's driving destinations, active first. Include archived ones only when
     * asked (they're kept so historical trips still resolve a name/type).
     *
     * @return array<int, array{id:int, name:string, type:string, round_trip:float,
     *   home_address:string, dest_address:string, archived:bool}>
     */
    public function destinations(int $userId, bool $includeArchived = false): array
    {
        $sql = 'SELECT id, name, type, round_trip_km, home_address, dest_address, archived_at
                FROM mileage_destinations WHERE user_id = :u';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY archived_at IS NULL DESC, name ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'           => (int) $r['id'],
                'name'         => (string) $r['name'],
                'type'         => (string) $r['type'] === self::TYPE_COMMUTE ? self::TYPE_COMMUTE : self::TYPE_BUSINESS,
                'round_trip'   => round((float) $r['round_trip_km'], 2),
                'home_address' => $r['home_address'] !== null ? (string) $r['home_address'] : '',
                'dest_address' => $r['dest_address'] !== null ? (string) $r['dest_address'] : '',
                'archived'     => $r['archived_at'] !== null,
            ];
        }

        return $out;
    }

    /** @return array<int, array{id:int, name:string, type:string, round_trip:float}> keyed by id */
    private function destinationsMap(int $userId): array
    {
        $map = [];
        foreach ($this->destinations($userId, true) as $d) {
            $map[$d['id']] = ['id' => $d['id'], 'name' => $d['name'], 'type' => $d['type'], 'round_trip' => $d['round_trip']];
        }

        return $map;
    }

    public function addDestination(
        int $userId,
        string $name,
        string $type = self::TYPE_BUSINESS,
        float $roundTripKm = 0.0,
        ?string $home = null,
        ?string $dest = null
    ): int {
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '') {
            throw new RuntimeException('A destination name is required.');
        }
        $type = $type === self::TYPE_COMMUTE ? self::TYPE_COMMUTE : self::TYPE_BUSINESS;

        $stmt = $this->db->prepare(
            'INSERT INTO mileage_destinations (user_id, name, type, round_trip_km, home_address, dest_address)
             VALUES (:u, :n, :t, :km, :h, :d)'
        );
        $stmt->execute([
            ':u'  => $userId,
            ':n'  => $name,
            ':t'  => $type,
            ':km' => round(max(0.0, $roundTripKm), 2),
            ':h'  => $home !== null && trim($home) !== '' ? mb_substr(trim($home), 0, 255) : null,
            ':d'  => $dest !== null && trim($dest) !== '' ? mb_substr(trim($dest), 0, 255) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates a destination's fields (only the ones passed). Returns true if the row
     * belongs to the user and was updated.
     */
    public function updateDestination(int $userId, int $id, array $fields): bool
    {
        $set = [];
        $args = [':id' => $id, ':u' => $userId];
        if (array_key_exists('name', $fields)) {
            $name = mb_substr(trim((string) $fields['name']), 0, 120);
            if ($name === '') {
                throw new RuntimeException('A destination name is required.');
            }
            $set[] = 'name = :n';
            $args[':n'] = $name;
        }
        if (array_key_exists('type', $fields)) {
            $set[] = 'type = :t';
            $args[':t'] = ((string) $fields['type']) === self::TYPE_COMMUTE ? self::TYPE_COMMUTE : self::TYPE_BUSINESS;
        }
        if (array_key_exists('round_trip_km', $fields)) {
            $set[] = 'round_trip_km = :km';
            $args[':km'] = round(max(0.0, (float) $fields['round_trip_km']), 2);
        }
        if (array_key_exists('home_address', $fields)) {
            $h = trim((string) $fields['home_address']);
            $set[] = 'home_address = :h';
            $args[':h'] = $h !== '' ? mb_substr($h, 0, 255) : null;
        }
        if (array_key_exists('dest_address', $fields)) {
            $d = trim((string) $fields['dest_address']);
            $set[] = 'dest_address = :d';
            $args[':d'] = $d !== '' ? mb_substr($d, 0, 255) : null;
        }
        if ($set === []) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE mileage_destinations SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :u'
        );
        $stmt->execute($args);

        return $stmt->rowCount() > 0;
    }

    /** Soft-deletes a destination (its trips stay for history). */
    public function archiveDestination(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE mileage_destinations SET archived_at = NOW() WHERE id = :id AND user_id = :u AND archived_at IS NULL'
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** The destination to use when the caller didn't name one: the sole active one, else the first active business one. */
    public function defaultDestinationId(int $userId): ?int
    {
        $active = $this->destinations($userId, false);
        if ($active === []) {
            return null;
        }
        if (count($active) === 1) {
            return $active[0]['id'];
        }
        foreach ($active as $d) {
            if ($d['type'] === self::TYPE_BUSINESS) {
                return $d['id'];
            }
        }

        return $active[0]['id'];
    }

    /** Resolves a destination by (loose) name for the assistant tool. Null if no match. */
    public function findDestinationByName(int $userId, string $name): ?int
    {
        $needle = self::normalizeName($name);
        if ($needle === '') {
            return null;
        }
        foreach ($this->destinations($userId, false) as $d) {
            if (self::normalizeName($d['name']) === $needle) {
                return $d['id'];
            }
        }

        return null;
    }

    private static function normalizeName(string $s): string
    {
        return preg_replace('/[^a-z0-9æøå]/u', '', mb_strtolower(trim($s))) ?? '';
    }

    // ---- Trips -------------------------------------------------------------

    /**
     * Logs a driving day to a destination. km defaults to that destination's round-trip
     * distance (falling back to the legacy single setting if no destination resolves).
     */
    public function logTrip(
        int $userId,
        ?int $destinationId = null,
        ?string $date = null,
        ?float $km = null,
        ?string $note = null
    ): int {
        $date = $date !== null && trim($date) !== '' ? date('Y-m-d', strtotime($date) ?: time()) : self::today();

        if ($destinationId !== null) {
            $destinationId = $this->ownedDestinationId($userId, $destinationId);
        }
        $destinationId ??= $this->defaultDestinationId($userId);

        if ($km === null || $km <= 0) {
            $km = $this->distanceFor($userId, $destinationId);
        }
        $note = $note !== null ? mb_substr(trim($note), 0, 255) : null;

        $stmt = $this->db->prepare(
            'INSERT INTO mileage_trips (user_id, destination_id, trip_date, km, note) VALUES (:u, :dest, :d, :k, :n)'
        );
        $stmt->execute([
            ':u'    => $userId,
            ':dest' => $destinationId,
            ':d'    => $date,
            ':k'    => round(max(0.0, $km), 2),
            ':n'    => $note,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Confirms a destination id belongs to the user (returns it) or null. */
    private function ownedDestinationId(int $userId, int $id): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM mileage_destinations WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->fetchColumn() !== false ? $id : null;
    }

    private function distanceFor(int $userId, ?int $destinationId): float
    {
        if ($destinationId !== null) {
            $stmt = $this->db->prepare('SELECT round_trip_km FROM mileage_destinations WHERE id = :id AND user_id = :u');
            $stmt->execute([':id' => $destinationId, ':u' => $userId]);
            $v = $stmt->fetchColumn();
            if ($v !== false && (float) $v > 0) {
                return round((float) $v, 2);
            }
        }

        return $this->settings->mileageConfig($userId)['round_trip']; // legacy fallback
    }

    /**
     * Corrects a logged driving day in place (report #20: the assistant could only ADD
     * trips, so "that was commute, not business" produced duplicates instead of a fix).
     * $fields: destination_id, date (YYYY-MM-DD), km, note — only the keys given change.
     * Moving a trip to another destination without an explicit km re-derives the km from
     * that destination's round trip (the old km belonged to the old destination).
     *
     * @param array{destination_id?:int, date?:string, km?:float, note?:?string} $fields
     */
    public function updateTrip(int $userId, int $id, array $fields): bool
    {
        $trip = $this->findTrip($userId, $id);
        if ($trip === null) {
            return false;
        }

        $destId = $trip['destination_id'];
        if (isset($fields['destination_id'])) {
            $owned = $this->ownedDestinationId($userId, (int) $fields['destination_id']);
            if ($owned === null) {
                throw new RuntimeException('Unknown destination.');
            }
            $destId = $owned;
        }
        $km = isset($fields['km']) && (float) $fields['km'] > 0
            ? round((float) $fields['km'], 2)
            : ($destId !== $trip['destination_id'] ? $this->distanceFor($userId, $destId) : $trip['km']);
        $date = isset($fields['date']) && trim((string) $fields['date']) !== ''
            ? date('Y-m-d', strtotime((string) $fields['date']) ?: strtotime($trip['date']))
            : $trip['date'];
        $note = array_key_exists('note', $fields)
            ? ($fields['note'] !== null && trim((string) $fields['note']) !== '' ? mb_substr(trim((string) $fields['note']), 0, 255) : null)
            : ($trip['note'] !== '' ? $trip['note'] : null);

        $stmt = $this->db->prepare(
            'UPDATE mileage_trips SET destination_id = :dest, trip_date = :d, km = :k, note = :n
             WHERE id = :id AND user_id = :u'
        );
        $stmt->execute([':dest' => $destId, ':d' => $date, ':k' => $km, ':n' => $note, ':id' => $id, ':u' => $userId]);

        return true;
    }

    /**
     * One of the user's trips, or null.
     *
     * @return array{id:int, destination_id:?int, date:string, km:float, note:string}|null
     */
    public function findTrip(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, destination_id, trip_date, km, note FROM mileage_trips WHERE id = :id AND user_id = :u'
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }

        return [
            'id'             => (int) $r['id'],
            'destination_id' => $r['destination_id'] !== null ? (int) $r['destination_id'] : null,
            'date'           => substr((string) $r['trip_date'], 0, 10),
            'km'             => (float) $r['km'],
            'note'           => (string) ($r['note'] ?? ''),
        ];
    }

    /**
     * The card's trip rows trimmed for the MODEL (the card itself is stripped before the
     * model sees it): id, date, destination, business/commute and km — so it can spot a
     * wrong destination or a duplicate day and fix it with update_trip / delete_trip.
     *
     * @param array<string, mixed> $card from card()
     * @return list<array<string, mixed>>
     */
    public static function tripsForModel(array $card, int $limit = 25, ?string $date = null): array
    {
        $out = [];
        foreach ($card['trips'] ?? [] as $t) {
            if ($date !== null && $t['date'] !== $date) {
                continue;
            }
            $out[] = [
                'id'          => $t['id'],
                'date'        => $t['date'],
                'destination' => $t['destination'],
                'counted_as'  => $t['bucket'],
                'km'          => $t['km'],
                'note'        => $t['note'],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function deleteTrip(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM mileage_trips WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    // ---- Classification (pure) --------------------------------------------

    /**
     * Classifies every logged trip (chronological) into business vs commuter and its
     * deductible amount. Fetches, then delegates to the pure classifyRows().
     *
     * @return array<int, array{id:int, date:string, km:float, note:string,
     *   destination_id:int, destination_name:string, destination_type:string,
     *   bucket:string, business_km:float, business_amount:float, commuter_amount:float,
     *   rolling_count:int}>
     */
    public function classify(int $userId): array
    {
        $rates = $this->settings->mileageConfig($userId);

        $stmt = $this->db->prepare(
            'SELECT id, destination_id, trip_date, km, note FROM mileage_trips
             WHERE user_id = :u ORDER BY trip_date ASC, id ASC'
        );
        $stmt->execute([':u' => $userId]);

        return self::classifyRows($stmt->fetchAll(), $this->destinationsMap($userId), $rates);
    }

    /**
     * Pure classifier — no DB, unit-testable with plain arrays (the local CLI has no PDO
     * driver, so the tax logic lives here). The 60-day counter is PER business destination;
     * commute destinations are always befordringsfradrag; the 20,000 km/year high-rate
     * tier is global per year across business destinations.
     *
     * @param array<int, array{id:int|string, destination_id:int|string|null, trip_date:string, km:float|string, note:?string}> $trips ordered asc
     * @param array<int, array{id:int, name:string, type:string, round_trip:float}> $dests keyed by id
     * @param array{rate_high:float, rate_low:float, commute:float, commute_far:float} $rates
     * @return array<int, array<string, mixed>>
     */
    public static function classifyRows(array $trips, array $dests, array $rates): array
    {
        // Per business-destination timestamps, for the rolling 60-day per-destination count.
        $bizDatesByDest = [];
        foreach ($trips as $t) {
            $destId = (int) ($t['destination_id'] ?? 0);
            $type   = $dests[$destId]['type'] ?? self::TYPE_BUSINESS;
            if ($type === self::TYPE_BUSINESS) {
                $bizDatesByDest[$destId][] = (int) strtotime((string) $t['trip_date']);
            }
        }

        $yearBizKm = [];   // 'YYYY' => cumulative business km that year (global, for the 20k tier)
        $out = [];
        foreach ($trips as $t) {
            $destId  = (int) ($t['destination_id'] ?? 0);
            $dest    = $dests[$destId] ?? null;
            $type    = $dest['type'] ?? self::TYPE_BUSINESS;   // legacy trips (no dest) → business
            $km      = round((float) $t['km'], 2);
            $year    = substr((string) $t['trip_date'], 0, 4);
            $ts      = (int) strtotime((string) $t['trip_date']);

            $bucket  = 'commuter';
            $bizKm   = 0.0; $bizAmt = 0.0; $comAmt = 0.0;
            $count   = 0;

            if ($type === self::TYPE_COMMUTE) {
                // Fixed workplace — befordringsfradrag from day 1, never business.
                $comAmt = self::commuteAmount($km, $rates);
            } else {
                // Business destination — 60-day rule within this destination's own history.
                $floor = strtotime('-1 year +1 day', $ts) ?: $ts;
                foreach ($bizDatesByDest[$destId] ?? [] as $d) {
                    if ($d <= $ts && $d >= $floor) {
                        $count++;
                    }
                }
                if ($count <= self::BUSINESS_DAY_LIMIT) {
                    $bucket = 'business';
                    $bizKm  = $km;
                    $prior  = $yearBizKm[$year] ?? 0.0;
                    $highKm = max(0.0, min($km, self::YEAR_KM_TIER - $prior));
                    $lowKm  = $km - $highKm;
                    $bizAmt = $highKm * $rates['rate_high'] + $lowKm * $rates['rate_low'];
                    $yearBizKm[$year] = $prior + $km;
                } else {
                    $comAmt = self::commuteAmount($km, $rates);
                }
            }

            $out[] = [
                'id'               => (int) $t['id'],
                'date'             => (string) $t['trip_date'],
                'km'               => $km,
                'note'             => isset($t['note']) && $t['note'] !== null ? (string) $t['note'] : '',
                'destination_id'   => $destId,
                'destination_name' => $dest['name'] ?? '',
                'destination_type' => $type,
                'bucket'           => $bucket,
                'business_km'      => $bizKm,
                'business_amount'  => round($bizAmt, 2),
                'commuter_amount'  => round($comAmt, 2),
                'rolling_count'    => $count,
            ];
        }

        return $out;
    }

    /** @param array{commute:float, commute_far:float} $rates */
    private static function commuteAmount(float $km, array $rates): float
    {
        $band1 = max(0.0, min($km, (float) self::COMMUTE_BAND_KM) - self::COMMUTE_FREE_KM);
        $band2 = max(0.0, $km - self::COMMUTE_BAND_KM);

        return $band1 * $rates['commute'] + $band2 * $rates['commute_far'];
    }

    /**
     * Business (erhvervsmæssig) deduction for trips in a period — for the P&L / tax
     * reserve. All-time when no range. Commuter driving (day 61+ AND all commute-type
     * destinations such as DTU) is deliberately excluded — it's a personal deduction.
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

    // ---- Card --------------------------------------------------------------

    /**
     * The mileage card (kind: mileage) for a year at $offset (0 = current, -1 = last),
     * with a per-destination breakdown and each business destination's own 60-day counter.
     *
     * @return array<string, mixed>
     */
    public function card(int $userId, int $offset = 0): array
    {
        $cfg   = $this->settings->mileageConfig($userId);
        $tz    = new DateTimeZone(self::LOCAL_TZ);
        $year  = (int) (new DateTimeImmutable('now', $tz))->format('Y') + $offset;
        $rows  = $this->classify($userId);
        $dests = $this->destinations($userId, false);

        // Trailing-12-month driving-day count per destination as of today (for the counter).
        $todayTs = strtotime(self::today()) ?: time();
        $floor   = strtotime('-1 year +1 day', $todayTs) ?: $todayTs;
        $count12ByDest = [];
        foreach ($rows as $r) {
            $d = strtotime($r['date']) ?: 0;
            if ($d <= $todayTs && $d >= $floor) {
                $count12ByDest[$r['destination_id']] = ($count12ByDest[$r['destination_id']] ?? 0) + 1;
            }
        }

        // Year aggregates: overall + per destination.
        $bizDays = 0; $bizKm = 0.0; $bizAmt = 0.0;
        $comDays = 0; $comKm = 0.0; $comAmt = 0.0;
        $perDest = [];   // destId => ['biz'=>[days,km,amt], 'com'=>[days,km,amt]]
        $trips   = [];
        foreach ($rows as $r) {
            if ((int) substr($r['date'], 0, 4) !== $year) {
                continue;
            }
            $id = $r['destination_id'];
            $perDest[$id] ??= ['biz' => [0, 0.0, 0.0], 'com' => [0, 0.0, 0.0]];
            if ($r['bucket'] === 'business') {
                $bizDays++; $bizKm += $r['km']; $bizAmt += $r['business_amount'];
                $perDest[$id]['biz'][0]++; $perDest[$id]['biz'][1] += $r['km']; $perDest[$id]['biz'][2] += $r['business_amount'];
            } else {
                $comDays++; $comKm += $r['km']; $comAmt += $r['commuter_amount'];
                $perDest[$id]['com'][0]++; $perDest[$id]['com'][1] += $r['km']; $perDest[$id]['com'][2] += $r['commuter_amount'];
            }
            $trips[] = [
                'id'          => $r['id'], 'date' => $r['date'], 'km' => $r['km'], 'note' => $r['note'],
                'bucket'      => $r['bucket'], 'destination_id' => $id, 'destination' => $r['destination_name'],
                'amount'      => $r['bucket'] === 'business' ? $r['business_amount'] : $r['commuter_amount'],
            ];
        }
        usort($trips, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        $destinations = [];
        foreach ($dests as $d) {
            $agg   = $perDest[$d['id']] ?? ['biz' => [0, 0.0, 0.0], 'com' => [0, 0.0, 0.0]];
            $entry = [
                'id'           => $d['id'],
                'name'         => $d['name'],
                'type'         => $d['type'],
                'round_trip'   => $d['round_trip'],
                'home_address' => $d['home_address'],
                'dest_address' => $d['dest_address'],
                'business'     => ['days' => $agg['biz'][0], 'km' => round($agg['biz'][1], 2), 'amount' => round($agg['biz'][2], 2)],
                'commuter'     => ['days' => $agg['com'][0], 'km' => round($agg['com'][1], 2), 'amount' => round($agg['com'][2], 2)],
            ];
            if ($d['type'] === self::TYPE_BUSINESS) {
                $logged = $count12ByDest[$d['id']] ?? 0;
                $entry['counter'] = [
                    'limit'         => self::BUSINESS_DAY_LIMIT,
                    'logged_12mo'   => $logged,
                    'business_used' => min($logged, self::BUSINESS_DAY_LIMIT),
                    'remaining'     => max(0, self::BUSINESS_DAY_LIMIT - $logged),
                    'commuting_now' => $logged >= self::BUSINESS_DAY_LIMIT,
                ];
            }
            $destinations[] = $entry;
        }

        return [
            'kind'           => 'mileage',
            'title'          => 'Mileage · ' . $year,
            'currency'       => 'DKK',
            'offset'         => $offset,
            'year'           => $year,
            'can_next'       => $offset < 0,
            'period_label'   => (string) $year,
            'rates'          => $cfg,
            'has_destinations' => $dests !== [],
            'business'       => ['days' => $bizDays, 'km' => round($bizKm, 2), 'amount' => round($bizAmt, 2)],
            'commuter'       => ['days' => $comDays, 'km' => round($comKm, 2), 'amount' => round($comAmt, 2)],
            'destinations'   => $destinations,
            'trips'          => array_slice($trips, 0, 100),
        ];
    }
}
