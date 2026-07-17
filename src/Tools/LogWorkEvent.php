<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkEvents;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Tool: manually record or correct a work clock in/out — e.g. "I arrived at 8:30",
 * or "I forgot to clock out yesterday, I left at 17:00". Complements the automatic
 * geofence punches.
 */
final class LogWorkEvent implements Tool
{
    public function __construct(private WorkEvents $events)
    {
    }

    public function name(): string
    {
        return 'log_work_event';
    }

    public function description(): string
    {
        return 'Manually records a work clock-in or clock-out, for corrections or when the automatic '
            . 'trigger was missed (e.g. "I forgot to clock out yesterday, I left at 17:00"). Give "at" '
            . 'as the LOCAL wall-clock time the user stated, format "YYYY-MM-DD HH:MM" (24h) — do NOT '
            . 'convert to UTC or add a "Z"; the app handles the timezone. Omit "at" for right now. '
            . 'To record a WHOLE past session in ONE call (e.g. "Wednesday I worked 9–15"), set '
            . 'kind="in", "at" to the start and "out_at" to the end — do NOT make two separate calls. '
            . 'Otherwise, to close an ongoing session, log a single "out" at the time they left. If the '
            . 'user has multiple workplaces, pass "place" (the same label) so it pairs with the right '
            . 'session. The result shows that day\'s card so you can confirm in one step — trust it, '
            . 'don\'t re-check.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'kind' => [
                    'type'        => 'string',
                    'enum'        => ['in', 'out'],
                    'description' => '"in" for arriving/clocking in, "out" for leaving/clocking out.',
                ],
                'at' => [
                    'type'        => 'string',
                    'description' => 'Local wall-clock time the user said, "YYYY-MM-DD HH:MM" '
                        . '(e.g. "2026-07-10 00:15"). Do NOT convert to UTC. Omit for now.',
                ],
                'out_at' => [
                    'type'        => 'string',
                    'description' => 'Optional. With kind="in" and "at" as the start, this LOCAL '
                        . 'clock-out time ("YYYY-MM-DD HH:MM") records a complete session (both punches) '
                        . 'in one call. Must be after "at".',
                ],
                'place' => [
                    'type'        => 'string',
                    'description' => 'Workplace label, if the user has more than one (e.g. "Office").',
                ],
                'note' => [
                    'type'        => 'string',
                    'description' => 'Optional short note.',
                ],
            ],
            'required' => ['kind'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $kind = (string) ($arguments['kind'] ?? '');
        if ($kind !== 'in' && $kind !== 'out') {
            return ['error' => 'kind must be "in" or "out".'];
        }
        $note  = isset($arguments['note']) ? (string) $arguments['note'] : null;
        $place = isset($arguments['place']) ? (string) $arguments['place'] : null;

        // The model states the user's LOCAL wall-clock time; convert it to UTC here
        // (interpreting it in Europe/Copenhagen) so the model can't get the offset
        // wrong. Omitted → WorkEvents::add uses "now".
        $atUtc = null;
        if (isset($arguments['at']) && trim((string) $arguments['at']) !== '') {
            $atUtc = $this->localToUtc((string) $arguments['at']);
            if ($atUtc === null) {
                return ['error' => 'I could not read that time. Give it like "2026-07-10 08:30".'];
            }
        }

        $outAtUtc = null;
        if (isset($arguments['out_at']) && trim((string) $arguments['out_at']) !== '') {
            $outAtUtc = $this->localToUtc((string) $arguments['out_at']);
            if ($outAtUtc === null) {
                return ['error' => 'I could not read the clock-out time. Give it like "2026-07-10 15:00".'];
            }
        }

        // Whole session in one call: an 'in' at "at" plus an 'out' at "out_at".
        if ($outAtUtc !== null) {
            if ($kind !== 'in' || $atUtc === null) {
                return ['error' => 'To record a full session, use kind="in" with both "at" (start) and "out_at" (end).'];
            }
            if (strtotime($outAtUtc) <= strtotime($atUtc)) {
                return ['error' => 'The clock-out time must be after the clock-in time.'];
            }
            try {
                $this->events->add($userId, 'in', $atUtc, 'assistant', $note, $place);
                $this->events->add($userId, 'out', $outAtUtc, 'assistant', null, $place);
            } catch (\Exception $e) {
                return ['error' => 'I could not read that time. Give it as a clear date/time.'];
            }
            $day     = $this->localDateFor($atUtc);
            $summary = $this->events->summary($userId, 'today', $day);

            return [
                'recorded'   => true,
                'session'    => true,
                'day'        => $summary['range_label'],
                'day_total'  => $summary['total_label'],
                '_render'    => $summary['card'],
            ];
        }

        // Clocking out without naming a place: if there's exactly one open session,
        // attach to it (a placeless 'out' otherwise wouldn't pair with a placed
        // 'in', since pairing is per workplace). "I punched out at 4pm" → closes it.
        if ($kind === 'out' && ($place === null || trim($place) === '')) {
            $open = $this->events->openSessions($userId);
            if (count($open) === 1) {
                $place = $open[0]['place'];
            }
        }

        try {
            $res = $this->events->add($userId, $kind, $atUtc, 'assistant', $note, $place);
        } catch (\Exception $e) {
            return ['error' => 'I could not read that time. Give it as a clear date/time.'];
        }

        // Show the affected day (not always "today") so the model gets correct
        // confirmation of a backdated punch and doesn't loop re-checking.
        $day     = $this->localDateFor($atUtc);
        $summary = $this->events->summary($userId, 'today', $day);

        return [
            'recorded'      => $res['status'] === 'ok',
            'duplicate'     => $res['status'] === 'duplicate',
            'kind'          => $res['kind'],
            'at'            => $res['local'],
            'day'           => $summary['range_label'],
            'day_total'     => $summary['total_label'],
            'clocked_in'    => $summary['ongoing'],
            '_render'       => $summary['card'],
        ];
    }

    /** Local (Europe/Copenhagen) calendar date 'Y-m-d' for a UTC instant, or today. */
    private function localDateFor(?string $utc): string
    {
        $tz = new DateTimeZone(WorkEvents::LOCAL_TZ);
        $dt = $utc !== null ? new DateTimeImmutable($utc) : new DateTimeImmutable('now');

        return $dt->setTimezone($tz)->format('Y-m-d');
    }

    /**
     * Interprets a local wall-clock time (Europe/Copenhagen) and returns it as an
     * RFC3339 UTC string, or null if unparseable. Any trailing "Z"/offset the model
     * mistakenly appended is stripped so the time is always read as local.
     */
    private function localToUtc(string $at): ?string
    {
        $s = preg_replace('/\s*(Z|[+-]\d{2}:?\d{2})\s*$/i', '', trim($at)) ?? trim($at);
        try {
            $local = new DateTimeImmutable($s, new DateTimeZone(WorkEvents::LOCAL_TZ));
        } catch (\Exception $e) {
            return null;
        }

        return $local->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }
}
