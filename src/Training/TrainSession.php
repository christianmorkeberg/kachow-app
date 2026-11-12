<?php

declare(strict_types=1);

namespace App\Training;

use App\Data\Connections;
use App\Data\TrainingPrograms;
use App\Data\Users;
use App\Data\Workouts;
use App\Database;
use App\Support\SetPlan;
use App\Support\TrainingLoad as Calc;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * The Train card: one programme day, done by one person or by two on the same phone. Builds the card (every exercise as one lane per person: each set with its target
 * percentage, the weight that gives from THAT person's training max, what they logged so far, and last time's weight and comment) and takes the actions behind it —
 * tick a set (saved to that person's own log at once, with what was planned beside what was done), untick it, comment, set a training max. Nothing here changes the
 * programme: adjusting a percentage on the day only changes what is logged.
 *
 * Two people on one phone: the person logged in may log for the other only through the sharing they already have (the other must have shared "workouts" with them).
 */
final class TrainSession
{
    private const TZ = 'Europe/Copenhagen';

    private PDO $db;

    public function __construct(
        private TrainingPrograms $programs,
        private TrainingMax $maxes,
        private Workouts $workouts,
        private Connections $connections,
        private Users $users,
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Database::get();
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d');
    }

    // ---------------------------------------------------------------- the card

    /** @return array<string, mixed> */
    public function card(int $viewerId, string $date): array
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : self::today();
        $d    = new DateTimeImmutable($date);
        $base = ['kind' => 'train', 'date' => $date, 'today' => self::today(), 'prev' => $d->modify('-1 day')->format('Y-m-d'), 'next' => $d->modify('+1 day')->format('Y-m-d')];
        $day  = $this->programs->dayFor($viewerId, $date, false);
        if ($day === null || $day['items'] === []) {
            return $base + ['empty' => true, 'people' => [], 'exercises' => [], 'progress' => ['done' => 0, 'total' => 0]];
        }
        $program = $day['programme'];
        $people  = $this->people($viewerId, $program, $day['audience']);
        $session = $this->sessionFor((int) $program['id'], $date);
        $sets    = [];   // person id => session set rows
        foreach ($people as $p) {
            $sets[$p['id']] = $session !== null ? $this->workouts->sessionSets($p['id'], (int) $session['id']) : [];
        }

        $exercises = [];
        $done = $total = 0;
        foreach ($day['items'] as $item) {
            $targets = SetPlan::expand($item);
            $lanes   = [];
            foreach ($people as $p) {
                $mx   = $this->maxes->forExercise($p['id'], $item['exercise']);
                $last = $this->workouts->lastTime($p['id'], $mx['exercise'], $date);
                $mine = array_values(array_filter($sets[$p['id']], static fn (array $r): bool => $r['exercise'] === $mx['exercise']));
                $bySet = [];
                foreach ($mine as $r) {
                    $bySet[(int) $r['set_no']] = $r;
                }
                $cells = [];
                foreach ($targets as $i => $t) {
                    $n   = $i + 1;
                    $row = $bySet[$n] ?? null;
                    $planned = self::plannedWeight($t, $mx['max']);
                    $weight  = $row !== null ? ($row['weight'] !== null ? (float) $row['weight'] : null) : $planned;
                    $ghost   = false;
                    if ($row === null && $weight === null && $last !== null && $last['weight'] !== null) {
                        $weight = $last['weight'];   // nothing planned: start from what was used last time
                        $ghost  = true;
                    }
                    $cells[] = [
                        'n'          => $n,
                        'reps'       => $row !== null ? ($row['reps'] !== null ? (int) $row['reps'] : null) : $t['reps'],
                        'target_pct' => $t['percent'],
                        'planned_weight' => $planned,
                        'weight'     => $weight,
                        'percent'    => $weight !== null && $mx['max'] !== null && $mx['max'] > 0 ? round($weight / $mx['max'] * 100, 1) : $t['percent'],
                        'ghost'      => $ghost,
                        'done'       => $row !== null,
                        'workout_id' => $row !== null ? (int) $row['id'] : null,
                    ];
                    $total++;
                    $done += $row !== null ? 1 : 0;
                }
                $lanes[] = [
                    'person_id' => $p['id'], 'exercise' => $mx['exercise'], 'max' => $mx['max'], 'basis' => $mx['basis'],
                    'last' => $last, 'comment' => self::commentOf($mine), 'sets' => $cells,
                ];
            }
            $exercises[] = ['name' => $item['exercise'], 'label' => SetPlan::label($item), 'note' => $item['note'], 'lanes' => $lanes];
        }

        return $base + [
            'empty'     => false,
            'title'     => $day['title'] ?? $day['program'],
            'program'   => ['id' => (int) $program['id'], 'name' => $day['program'], 'label' => $day['label'], 'week' => $day['week'], 'weekday' => $day['weekday']],
            'started'   => $session !== null,
            'started_at' => $session !== null ? (string) $session['started_at'] : null,
            'people'    => $people,
            'exercises' => $exercises,
            'progress'  => ['done' => $done, 'total' => $total],
        ];
    }

    /** The weight a target gives for this person: a fixed weight as planned, else its percentage of their training max rounded to loadable plates, else null. @param array{percent:?float, weight:?float} $t */
    public static function plannedWeight(array $t, ?float $max): ?float
    {
        if ($t['weight'] !== null) {
            return $t['weight'];
        }

        return $t['percent'] !== null && $max !== null ? Calc::roundToStep(Calc::percentOf($max, $t['percent'])) : null;
    }

    /** @return list<array{id:int, name:string, first:string, me:bool, can_log:bool}> the people on the day, the viewer first */
    private function people(int $viewerId, array $program, ?int $audience): array
    {
        $ids = $this->programs->participants($program);
        if ($audience !== null) {
            $ids = [$audience];
        }
        usort($ids, static fn (int $a, int $b): int => [$a === $viewerId ? 0 : 1, $a] <=> [$b === $viewerId ? 0 : 1, $b]);
        $out = [];
        foreach ($ids as $id) {
            $u    = $this->users->findById($id);
            $name = trim((string) ($u['name'] ?? '')) ?: (string) ($u['email'] ?? '?');
            $out[] = ['id' => $id, 'name' => $name, 'first' => explode(' ', $name)[0], 'me' => $id === $viewerId, 'can_log' => $this->mayLogFor($viewerId, $id)];
        }

        return $out;
    }

    /** One's own sets always; the other person's only when they have shared "workouts" with the viewer. */
    public function mayLogFor(int $viewerId, int $personId): bool
    {
        if ($viewerId === $personId) {
            return true;
        }
        $scopes = $this->connections->sharedScopes($personId, $viewerId);

        return in_array('workouts', $scopes, true) || in_array(Connections::LEGACY_LOG_SCOPE, $scopes, true);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function commentOf(array $rows): ?string
    {
        foreach ($rows as $r) {
            $n = trim((string) preg_replace('/\s*·?\s*logged by .*$/u', '', (string) ($r['notes'] ?? '')));
            if ($n !== '' && strcasecmp($n, 'From plan') !== 0) {
                return $n;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- actions

    /**
     * Saves one set (or corrects it if that set was already ticked). Returns the saved row's id.
     *
     * @return array{ok:true, workout_id:int}|array{ok:false, error:string}
     */
    public function tick(int $viewerId, string $date, int $personId, string $exercise, int $setNo, ?float $weight, ?int $reps, ?float $plannedPercent, ?float $plannedWeight, ?string $note): array
    {
        $ctx = $this->context($viewerId, $date, $personId, $exercise);
        if (isset($ctx['error'])) {
            return ['ok' => false, 'error' => $ctx['error']];
        }
        if ($setNo < 1 || $setNo > SetPlan::MAX_SETS || ($weight !== null && ($weight < 0 || $weight > 1000)) || ($reps !== null && ($reps < 0 || $reps > 999))) {
            return ['ok' => false, 'error' => 'That set does not make sense.'];
        }
        $session = $this->ensureSession((int) $ctx['program']['id'], $date, (int) $ctx['week'], (int) $ctx['weekday'], $viewerId);
        $name    = $this->maxes->forExercise($personId, $exercise)['exercise'];
        $stamp   = $personId !== $viewerId ? ' · logged by ' . $this->nameOf($viewerId) : '';
        $notes   = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255 - mb_strlen($stamp)) . $stamp : ($stamp !== '' ? ltrim($stamp, ' ·') : null);
        $existing = null;
        foreach ($this->workouts->sessionSets($personId, $session) as $r) {
            if ($r['exercise'] === $name && (int) $r['set_no'] === $setNo) {
                $existing = $r;
            }
        }
        if ($existing !== null) {
            $this->workouts->update($personId, (int) $existing['id'], ['weight' => $weight, 'reps' => $reps, 'notes' => $notes ?? $existing['notes']]);
            $id = (int) $existing['id'];
        } else {
            $id = $this->workouts->logPlannedSet($personId, $name, [
                'weight' => $weight, 'reps' => $reps, 'notes' => $notes, 'session_id' => $session, 'set_no' => $setNo,
                'planned_percent' => $plannedPercent, 'planned_weight' => $plannedWeight,
            ]);
        }
        $this->db->prepare('UPDATE training_sessions SET last_tick_at = :t WHERE id = :id')->execute([':t' => gmdate('Y-m-d H:i:s'), ':id' => $session]);

        return ['ok' => true, 'workout_id' => $id];
    }

    /** Takes a ticked set back out of the log. @return array{ok:bool, error?:string} */
    public function untick(int $viewerId, int $personId, int $workoutId): array
    {
        if (!$this->mayLogFor($viewerId, $personId)) {
            return ['ok' => false, 'error' => 'That person has not shared their workouts with you.'];
        }
        if ($this->workouts->sessionSet($personId, $workoutId) === null) {
            return ['ok' => false, 'error' => 'No such set.'];
        }

        return ['ok' => $this->workouts->deleteByIds($personId, [$workoutId]) === 1];
    }

    /** Sets the comment on the sets already ticked for one person and exercise today. A comment typed before the first tick travels with that tick instead. @return array{ok:bool, updated:int, error?:string} */
    public function comment(int $viewerId, string $date, int $personId, string $exercise, string $text): array
    {
        $ctx = $this->context($viewerId, $date, $personId, $exercise);
        if (isset($ctx['error'])) {
            return ['ok' => false, 'updated' => 0, 'error' => $ctx['error']];
        }
        $session = $this->sessionFor((int) $ctx['program']['id'], $date);
        if ($session === null) {
            return ['ok' => true, 'updated' => 0];
        }
        $name  = $this->maxes->forExercise($personId, $exercise)['exercise'];
        $stamp = $personId !== $viewerId ? ' · logged by ' . $this->nameOf($viewerId) : '';
        $text  = trim($text) !== '' ? mb_substr(trim($text), 0, 255 - mb_strlen($stamp)) . $stamp : null;
        $n = 0;
        foreach ($this->workouts->sessionSets($personId, (int) $session['id']) as $r) {
            if ($r['exercise'] === $name) {
                $this->workouts->update($personId, (int) $r['id'], ['notes' => $text]);
                $n++;
            }
        }

        return ['ok' => true, 'updated' => $n];
    }

    /** The person's training max for an exercise (0 / null clears it back to what their log says). @return array{ok:bool, error?:string} */
    public function setMax(int $viewerId, int $personId, string $exercise, ?float $max): array
    {
        if (!$this->mayLogFor($viewerId, $personId)) {
            return ['ok' => false, 'error' => 'That person has not shared their workouts with you.'];
        }

        return $this->maxes->setOverride($personId, $exercise, $max) ? ['ok' => true] : ['ok' => false, 'error' => 'Could not save that max.'];
    }

    /** Stamps the start of the day's session ("Start training"). Ticking a set starts it too. @return array{ok:bool, error?:string} */
    public function start(int $viewerId, string $date): array
    {
        $day = $this->programs->dayFor($viewerId, $date, false);
        if ($day === null) {
            return ['ok' => false, 'error' => 'Nothing is planned that day.'];
        }
        $this->ensureSession((int) $day['program_id'], $date, (int) $day['week'], (int) $day['weekday'], $viewerId);

        return ['ok' => true];
    }

    // ---------------------------------------------------------------- helpers

    /** The programme day and the checks every set action needs. @return array<string, mixed> with 'error' when it is not allowed */
    private function context(int $viewerId, string $date, int $personId, string $exercise): array
    {
        $day = $this->programs->dayFor($viewerId, $date, false);
        if ($day === null) {
            return ['error' => 'Nothing is planned that day.'];
        }
        if (!$this->mayLogFor($viewerId, $personId)) {
            return ['error' => 'That person has not shared their workouts with you.'];
        }
        $on = $day['audience'] !== null ? [$day['audience']] : $this->programs->participants($day['programme']);
        if (!in_array($personId, $on, true)) {
            return ['error' => 'That day is not for that person.'];
        }
        $names = array_map(static fn (array $i): string => mb_strtolower($i['exercise']), $day['items']);
        if (!in_array(mb_strtolower(trim($exercise)), $names, true)) {
            return ['error' => 'That exercise is not on this day.'];
        }

        return ['program' => $day['programme'], 'week' => $day['week'], 'weekday' => $day['weekday']];
    }

    /** @return array<string, mixed>|null */
    private function sessionFor(int $programId, string $date): ?array
    {
        try {
            $st = $this->db->prepare('SELECT * FROM training_sessions WHERE program_id = :p AND session_date = :d ORDER BY id DESC LIMIT 1');
            $st->execute([':p' => $programId, ':d' => $date]);
            $r = $st->fetch();

            return $r === false ? null : $r;
        } catch (\Throwable $e) {
            return null;   // before the migration
        }
    }

    private function ensureSession(int $programId, string $date, int $week, int $weekday, int $viewerId): int
    {
        $s = $this->sessionFor($programId, $date);
        if ($s !== null) {
            return (int) $s['id'];
        }
        $now = gmdate('Y-m-d H:i:s');
        try {
            $this->db->prepare('INSERT INTO training_sessions (program_id, session_date, week_no, weekday, started_at, last_tick_at, created_by) VALUES (:p, :d, :w, :wd, :s, :l, :u)')
                ->execute([':p' => $programId, ':d' => $date, ':w' => $week, ':wd' => $weekday, ':s' => $now, ':l' => $now, ':u' => $viewerId]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Training sessions need the migration 2026-11-12_shared_training.sql.');
        }

        return (int) $this->db->lastInsertId();
    }

    private function nameOf(int $userId): string
    {
        $u = $this->users->findById($userId);

        return trim((string) ($u['name'] ?? '')) ?: (string) ($u['email'] ?? 'a connection');
    }
}
