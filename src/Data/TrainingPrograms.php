<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Support\SetPlan;
use DateTimeImmutable;
use PDO;

/**
 * Training programmes: a multi-week plan as data — a programme has days (a week + a weekday), days have exercises with sets / reps /
 * weight / a note. Weeks are either CALENDAR weeks (ISO week numbers: "uge 43", optionally pinned to a year) or RELATIVE (week 1, 2, 3 …
 * counted from a start date, optionally repeating every N weeks). dayFor() says what the programme prescribes on a date; the day becomes
 * that date's workout plan through Support\ProgramPlanner. Plain SQL with dates from PHP, so tests run on SQLite.
 */
final class TrainingPrograms
{
    public const MAX_PROGRAMS = 12;
    public const MAX_ITEMS_PER_DAY = 30;
    public const MAX_DAYS_PER_CALL = 60;

    private PDO $db;
    private ?bool $pairCols = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Whether the shared-training migration has run (connection_id / audience / percent / set_plan exist): before it, everything works as it always did. */
    private function migrated(): bool
    {
        if ($this->pairCols === null) {
            try {
                $this->db->query('SELECT connection_id FROM training_programs LIMIT 0');
                $this->pairCols = true;
            } catch (\Throwable $e) {
                $this->pairCols = false;
            }
        }

        return $this->pairCols;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    // ---------- programmes ----------

    /** A new programme (active). @return int its id */
    public function create(int $userId, string $name, string $weekMode = 'calendar', ?string $startDate = null, ?int $repeatWeeks = null, ?string $sourceNote = null): int
    {
        $this->db->prepare(
            'INSERT INTO training_programs (user_id, name, status, week_mode, start_date, repeat_weeks, source_note, created_at, updated_at)
             VALUES (:u, :n, \'active\', :m, :s, :r, :src, :now, :now2)'
        )->execute([
            ':u' => $userId, ':n' => mb_substr(trim($name), 0, 120), ':m' => $weekMode === 'relative' ? 'relative' : 'calendar',
            ':s' => $weekMode === 'relative' ? self::cleanDate($startDate) : null,
            ':r' => $weekMode === 'relative' && $repeatWeeks !== null && $repeatWeeks > 0 ? min(52, $repeatWeeks) : null,
            ':src' => $sourceNote !== null && trim($sourceNote) !== '' ? mb_substr($sourceNote, 0, 60000) : null,
            ':now' => self::now(), ':now2' => self::now(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function countAll(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM training_programs WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One of the user's programmes by id or name (exact, case-insensitive, then a unique "contains"). Null if none / ambiguous.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $userId, string $query): ?array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return null;
        }
        $all = $this->all($userId);
        foreach ($all as $p) {
            if ((string) $p['id'] === $q || mb_strtolower($p['name']) === $q) {
                return $p;
            }
        }
        $hits = array_values(array_filter($all, static fn (array $p): bool => str_contains(mb_strtolower($p['name']), $q) || str_contains($q, mb_strtolower($p['name']))));

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * The user's programmes, newest first, each with how much it covers.
     *
     * @return list<array<string, mixed>>
     */
    public function all(int $userId): array
    {
        $shared = $this->migrated()
            ? ' OR p.connection_id IN (SELECT c.id FROM user_connections c WHERE c.status = \'accepted\' AND (c.requester_id = :u2 OR c.addressee_id = :u3))'
            : '';
        $stmt = $this->db->prepare(
            'SELECT p.*, (p.source_note IS NOT NULL) AS has_source,
                    (SELECT COUNT(*) FROM training_program_days d WHERE d.program_id = p.id) AS day_count,
                    (SELECT MIN(week_no) FROM training_program_days d WHERE d.program_id = p.id) AS first_week,
                    (SELECT MAX(week_no) FROM training_program_days d WHERE d.program_id = p.id) AS last_week
             FROM training_programs p WHERE p.user_id = :u' . $shared . ' ORDER BY p.id DESC'
        );
        $stmt->execute($shared !== '' ? [':u' => $userId, ':u2' => $userId, ':u3' => $userId] : [':u' => $userId]);

        return array_map([self::class, 'programRow'], $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function get(int $userId, int $id): ?array
    {
        foreach ($this->all($userId) as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }

        return null;
    }

    /** Changes the programme's name / status / week mode / start / repeat. Unknown keys are ignored. @param array<string, mixed> $f */
    public function update(int $userId, int $id, array $f): bool
    {
        if ($this->get($userId, $id) === null) {
            return false;
        }
        $set = ['updated_at = :now'];
        $p   = [':now' => self::now(), ':id' => $id, ':u' => $userId];
        if (isset($f['name']) && trim((string) $f['name']) !== '') {
            $set[] = 'name = :n';
            $p[':n'] = mb_substr(trim((string) $f['name']), 0, 120);
        }
        if (isset($f['status']) && in_array($f['status'], ['active', 'archived'], true)) {
            $set[] = 'status = :st';
            $p[':st'] = $f['status'];
        }
        if (isset($f['week_mode']) && in_array($f['week_mode'], ['calendar', 'relative'], true)) {
            $set[] = 'week_mode = :m';
            $p[':m'] = $f['week_mode'];
        }
        if (array_key_exists('start_date', $f)) {
            $set[] = 'start_date = :sd';
            $p[':sd'] = self::cleanDate($f['start_date'] === null ? null : (string) $f['start_date']);
        }
        if (array_key_exists('repeat_weeks', $f)) {
            $set[] = 'repeat_weeks = :rw';
            $p[':rw'] = $f['repeat_weeks'] !== null && (int) $f['repeat_weeks'] > 0 ? min(52, (int) $f['repeat_weeks']) : null;
        }
        unset($p[':u']);
        $this->db->prepare('UPDATE training_programs SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($p);   // authorised by get() above: either person on a shared programme may edit it

        return true;
    }

    public function delete(int $userId, int $id): bool
    {
        $p = $this->get($userId, $id);
        if ($p === null || $p['user_id'] !== $userId) {
            return false;   // only the person who made a programme deletes it (the other one can edit, not remove)
        }
        // children first (SQLite tests have no cascade); MySQL cascades the same way
        $days = $this->db->prepare('SELECT id FROM training_program_days WHERE program_id = :p');
        $days->execute([':p' => $id]);
        foreach ($days->fetchAll(PDO::FETCH_COLUMN) as $dayId) {
            $this->db->prepare('DELETE FROM training_program_items WHERE day_id = :d')->execute([':d' => $dayId]);
        }
        $this->db->prepare('DELETE FROM training_program_days WHERE program_id = :p')->execute([':p' => $id]);
        $this->db->prepare('DELETE FROM training_programs WHERE id = :id AND user_id = :u')->execute([':id' => $id, ':u' => $userId]);

        return true;
    }

    // ---------- days ----------

    /**
     * Sets one day of a programme (replacing that day's exercises if it exists).
     *
     * @param list<array<string, mixed>> $exercises each {exercise, sets?, reps?, weight?, note?}
     * @return array{items:int, replaced:bool}|null null if the programme is not the user's
     */
    public function upsertDay(int $userId, int $programId, int $week, int $year, int $weekday, ?string $title, array $exercises, ?string $note = null): ?array
    {
        if ($this->get($userId, $programId) === null || $week < 1 || $week > 60 || $weekday < 1 || $weekday > 7) {
            return null;
        }
        $year = $year >= 2000 && $year <= 2100 ? $year : 0;
        $stmt = $this->db->prepare('SELECT id FROM training_program_days WHERE program_id = :p AND year = :y AND week_no = :w AND weekday = :d');
        $stmt->execute([':p' => $programId, ':y' => $year, ':w' => $week, ':d' => $weekday]);
        $dayId = $stmt->fetchColumn();
        $replaced = $dayId !== false;
        $t = $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 120) : null;
        $n = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;
        if ($replaced) {
            $this->db->prepare('UPDATE training_program_days SET title = :t, note = :n WHERE id = :id')->execute([':t' => $t, ':n' => $n, ':id' => $dayId]);
            $this->db->prepare('DELETE FROM training_program_items WHERE day_id = :d')->execute([':d' => $dayId]);
        } else {
            $this->db->prepare('INSERT INTO training_program_days (program_id, week_no, year, weekday, title, note) VALUES (:p, :w, :y, :d, :t, :n)')
                ->execute([':p' => $programId, ':w' => $week, ':y' => $year, ':d' => $weekday, ':t' => $t, ':n' => $n]);
            $dayId = (int) $this->db->lastInsertId();
        }
        $pos = 0;
        foreach ($exercises as $ex) {
            $name = mb_substr(trim((string) ($ex['exercise'] ?? '')), 0, 120);
            if ($name === '' || $pos >= self::MAX_ITEMS_PER_DAY) {
                continue;
            }
            $t    = SetPlan::normalize($ex);
            $args = [
                ':d' => $dayId, ':pos' => ++$pos, ':e' => $name, ':s' => $t['sets'], ':r' => $t['reps'], ':w' => $t['weight'],
                ':n' => isset($ex['note']) && trim((string) $ex['note']) !== '' ? mb_substr(trim((string) $ex['note']), 0, 255) : null,
            ];
            if ($this->migrated()) {
                $this->db->prepare('INSERT INTO training_program_items (day_id, position, exercise, sets, reps, weight, percent, set_plan, note) VALUES (:d, :pos, :e, :s, :r, :w, :pc, :sp, :n)')->execute(
                    $args + [':pc' => $t['percent'], ':sp' => $t['set_plan'] !== null ? json_encode($t['set_plan']) : null]
                );
            } else {
                $this->db->prepare('INSERT INTO training_program_items (day_id, position, exercise, sets, reps, weight, note) VALUES (:d, :pos, :e, :s, :r, :w, :n)')->execute($args);
            }
        }
        $this->db->prepare('UPDATE training_programs SET updated_at = :now WHERE id = :id')->execute([':now' => self::now(), ':id' => $programId]);

        return ['items' => $pos, 'replaced' => $replaced];
    }

    /** Removes days: a whole week, or one weekday of a week (or of every week when $week is null). Returns how many days went. */
    public function removeDays(int $userId, int $programId, ?int $week, ?int $weekday, int $year = 0): int
    {
        if ($this->get($userId, $programId) === null) {
            return 0;
        }
        $sql = 'SELECT id FROM training_program_days WHERE program_id = :p';
        $p   = [':p' => $programId];
        if ($week !== null) {
            $sql .= ' AND week_no = :w';
            $p[':w'] = $week;
            if ($year > 0) {
                $sql .= ' AND year = :y';
                $p[':y'] = $year;
            }
        }
        if ($weekday !== null) {
            $sql .= ' AND weekday = :d';
            $p[':d'] = $weekday;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($p);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->db->prepare('DELETE FROM training_program_items WHERE day_id = :d')->execute([':d' => $id]);
            $this->db->prepare('DELETE FROM training_program_days WHERE id = :d')->execute([':d' => $id]);
        }

        return count($ids);
    }

    /**
     * Every day of a programme (optionally one week) with its exercises, ordered by week then weekday.
     *
     * @return list<array{week:int, year:int, weekday:int, title:?string, note:?string, items:list<array<string, mixed>>}>
     */
    public function days(int $userId, int $programId, ?int $week = null): array
    {
        if ($this->get($userId, $programId) === null) {
            return [];
        }
        $sql = 'SELECT * FROM training_program_days WHERE program_id = :p';
        $p   = [':p' => $programId];
        if ($week !== null) {
            $sql .= ' AND week_no = :w';
            $p[':w'] = $week;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY year ASC, week_no ASC, weekday ASC');
        $stmt->execute($p);
        $out = [];
        foreach ($stmt->fetchAll() as $d) {
            $out[] = $this->dayRow($d);
        }

        return $out;
    }

    /**
     * What the user's ACTIVE programmes prescribe on a date (the most recently made one that has a day for it), or null.
     *
     * @return array{program_id:int, program:string, week:int, weekday:int, title:?string, note:?string, items:list<array<string, mixed>>, label:string}|null
     */
    public function dayFor(int $userId, string $date, bool $forUserOnly = true): ?array
    {
        $d = new DateTimeImmutable($date);
        foreach ($this->all($userId) as $p) {
            if ($p['status'] !== 'active') {
                continue;
            }
            $week = self::weekFor($p, $d);
            if ($week === null) {
                continue;
            }
            $weekday = (int) $d->format('N');
            $stmt = $this->db->prepare(
                'SELECT * FROM training_program_days
                 WHERE program_id = :p AND week_no = :w AND weekday = :d AND year IN (0, :y) ORDER BY year DESC LIMIT 1'
            );
            $stmt->execute([':p' => $p['id'], ':w' => $week, ':d' => $weekday, ':y' => $p['week_mode'] === 'calendar' ? (int) $d->format('o') : 0]);
            $row = $stmt->fetch();
            if ($row === false) {
                continue;
            }
            $day = $this->dayRow($row);
            if ($forUserOnly && $day['audience'] !== null && $day['audience'] !== $userId) {
                continue;   // a day that is only the other person's does not become MY plan
            }

            return [
                'program_id' => $p['id'], 'program' => $p['name'], 'week' => $week, 'weekday' => $weekday, 'title' => $day['title'], 'note' => $day['note'],
                'items' => $day['items'], 'label' => $p['name'] . ($p['week_mode'] === 'calendar' ? ' · uge ' . $week : ' · week ' . $week),
                'audience' => $day['audience'], 'year' => $p['week_mode'] === 'calendar' ? (int) $d->format('o') : 0, 'programme' => $p,
            ];
        }

        return null;
    }

    /** The programme's week number for a date (calendar: the ISO week; relative: counted from start_date, repeating), or null if outside it. @param array<string, mixed> $p */
    public static function weekFor(array $p, DateTimeImmutable $d): ?int
    {
        if ($p['week_mode'] === 'calendar') {
            return (int) $d->format('W');
        }
        if ($p['start_date'] === null) {
            return null;
        }
        $start = (new DateTimeImmutable($p['start_date']))->modify('monday this week');
        $that  = $d->modify('monday this week');
        $idx   = intdiv((int) $start->diff($that)->format('%r%a'), 7) + 1;
        if ($idx < 1) {
            return null;
        }
        if ($p['repeat_weeks'] !== null) {
            $idx = (($idx - 1) % (int) $p['repeat_weeks']) + 1;
        }

        return $idx;
    }

    // ---------- sharing ----------

    /**
     * Shares a programme with a connected person (the pair becomes the programme's other owner-in-practice: both see it and either can edit; only the maker deletes it).
     * $onlyMeWeekdays: weekdays (1–7) that are only for the person sharing — every other day is for both; null leaves the days as they are.
     */
    public function share(int $userId, int $programId, int $connectionId, ?array $onlyMeWeekdays = null): bool
    {
        $p = $this->get($userId, $programId);
        if ($p === null || $p['user_id'] !== $userId || !$this->migrated()) {
            return false;
        }
        $this->db->prepare('UPDATE training_programs SET connection_id = :c, updated_at = :now WHERE id = :id')->execute([':c' => $connectionId, ':now' => self::now(), ':id' => $programId]);
        if ($onlyMeWeekdays !== null) {
            foreach ($this->db->query('SELECT id, weekday FROM training_program_days WHERE program_id = ' . (int) $programId)->fetchAll() as $d) {
                $this->db->prepare('UPDATE training_program_days SET audience_user_id = :a WHERE id = :id')
                    ->execute([':a' => in_array((int) $d['weekday'], $onlyMeWeekdays, true) ? $userId : null, ':id' => $d['id']]);
            }
        }

        return true;
    }

    /** Stops sharing: the programme is the maker's alone again (the days' audiences are cleared — they only mean something with two people). */
    public function unshare(int $userId, int $programId): bool
    {
        $p = $this->get($userId, $programId);
        if ($p === null || $p['user_id'] !== $userId || !$this->migrated()) {
            return false;
        }
        $this->db->prepare('UPDATE training_programs SET connection_id = NULL, updated_at = :now WHERE id = :id')->execute([':now' => self::now(), ':id' => $programId]);
        $this->db->prepare('UPDATE training_program_days SET audience_user_id = NULL WHERE program_id = :p')->execute([':p' => $programId]);

        return true;
    }

    /** Who a day is for: null = everyone on the programme, else that one person (who must be on it). */
    public function setDayAudience(int $userId, int $programId, int $week, int $year, int $weekday, ?int $audienceUserId): bool
    {
        $p = $this->get($userId, $programId);
        if ($p === null || !$this->migrated() || ($audienceUserId !== null && !in_array($audienceUserId, $this->participants($p), true))) {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE training_program_days SET audience_user_id = :a WHERE program_id = :p AND week_no = :w AND weekday = :d AND year IN (0, :y)');
        $stmt->execute([':a' => $audienceUserId, ':p' => $programId, ':w' => $week, ':d' => $weekday, ':y' => $year]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The people on a programme: its maker, and the other person of the pair when it is shared (both must still be connected).
     *
     * @param array<string, mixed> $program a programRow
     * @return list<int>
     */
    public function participants(array $program): array
    {
        $out = [(int) $program['user_id']];
        if (($program['connection_id'] ?? null) !== null) {
            $st = $this->db->prepare('SELECT requester_id, addressee_id FROM user_connections WHERE id = :c AND status = \'accepted\'');
            $st->execute([':c' => $program['connection_id']]);
            $c = $st->fetch();
            if ($c !== false) {
                foreach ([(int) $c['requester_id'], (int) $c['addressee_id']] as $id) {
                    if (!in_array($id, $out, true)) {
                        $out[] = $id;
                    }
                }
            }
        }

        return $out;
    }

    // ---------- helpers ----------

    /** @param array<string, mixed> $d @return array{week:int, year:int, weekday:int, audience:?int, title:?string, note:?string, items:list<array<string, mixed>>} */
    private function dayRow(array $d): array
    {
        $items = $this->db->prepare('SELECT * FROM training_program_items WHERE day_id = :d ORDER BY position ASC, id ASC');
        $items->execute([':d' => $d['id']]);

        return [
            'week' => (int) $d['week_no'], 'year' => (int) $d['year'], 'weekday' => (int) $d['weekday'],
            'audience' => isset($d['audience_user_id']) && $d['audience_user_id'] !== null ? (int) $d['audience_user_id'] : null,
            'title' => $d['title'] !== null ? (string) $d['title'] : null, 'note' => $d['note'] !== null ? (string) $d['note'] : null,
            'items' => array_map(static fn (array $i): array => [
                'exercise' => (string) $i['exercise'], 'sets' => $i['sets'] !== null ? (int) $i['sets'] : null, 'reps' => $i['reps'] !== null ? (int) $i['reps'] : null,
                'weight' => $i['weight'] !== null ? (float) $i['weight'] : null, 'note' => $i['note'] !== null ? (string) $i['note'] : null,
                'percent' => isset($i['percent']) && $i['percent'] !== null ? (float) $i['percent'] : null,
                'set_plan' => isset($i['set_plan']) && $i['set_plan'] !== null && is_array($sp = json_decode((string) $i['set_plan'], true)) ? $sp : null,
            ], $items->fetchAll()),
        ];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function programRow(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'status' => (string) $r['status'], 'week_mode' => (string) $r['week_mode'],
            'start_date' => $r['start_date'] !== null ? (string) $r['start_date'] : null, 'repeat_weeks' => $r['repeat_weeks'] !== null ? (int) $r['repeat_weeks'] : null,
            'days' => (int) ($r['day_count'] ?? 0), 'first_week' => $r['first_week'] !== null ? (int) $r['first_week'] : null, 'last_week' => $r['last_week'] !== null ? (int) $r['last_week'] : null,
            'has_source' => (bool) ($r['has_source'] ?? false), 'user_id' => (int) $r['user_id'],
            'connection_id' => isset($r['connection_id']) && $r['connection_id'] !== null ? (int) $r['connection_id'] : null,
        ];
    }

    private static function posInt(mixed $v, int $max): ?int
    {
        return is_numeric($v) && (int) $v >= 1 && (int) $v <= $max ? (int) $v : null;
    }

    private static function cleanDate(?string $s): ?string
    {
        return $s !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($s)) === 1 ? trim($s) : null;
    }
}
