<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ChangeLog;
use App\Data\WorkEvents;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: change the start and/or end of an EXISTING work session ("I stopped 30 minutes after I left", "I actually started at 08:15") in one step — instead of
 * deleting the session and recording it again. The old times are kept in the change log, so restore_change can put them back.
 */
final class UpdateWorkSession implements Tool
{
    public function __construct(private WorkEvents $events, private ?ChangeLog $log = null)
    {
    }

    public function name(): string
    {
        return 'update_work_session';
    }

    public function description(): string
    {
        return 'Changes the start and/or end time of an existing work session — "I stopped 30 minutes later", "I started at 08:15, not 07:56", "ret udstemplingen til 14:48". Pass `event_id` '
            . '(a session\'s in_id or out_id from get_work_hours) and `start` and/or `end` as local times: "HH:MM" (on the session\'s own day) or "YYYY-MM-DD HH:MM". '
            . 'An ongoing session (no clock-out yet) can be given an `end` to close it. Use this INSTEAD of delete_work_event + log_work_event for a correction. '
            . 'The previous times are logged and can be restored with restore_change.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'description' => 'The session\'s in_id or out_id (from get_work_hours).'],
                'start'    => ['type' => 'string', 'description' => 'New start, local "HH:MM" or "YYYY-MM-DD HH:MM".'],
                'end'      => ['type' => 'string', 'description' => 'New end, local "HH:MM" or "YYYY-MM-DD HH:MM".'],
            ],
            'required' => ['event_id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['event_id'] ?? 0);
        $s  = $id > 0 ? $this->events->sessionOf($userId, $id) : null;
        if ($s === null) {
            return ['updated' => false, 'error' => 'No such session (use an in_id or out_id from get_work_hours).'];
        }
        $startArg = trim((string) ($arguments['start'] ?? ''));
        $endArg   = trim((string) ($arguments['end'] ?? ''));
        if ($startArg === '' && $endArg === '') {
            return ['updated' => false, 'error' => 'Give a new start and/or end time.'];
        }
        $tz     = new DateTimeZone(WorkEvents::LOCAL_TZ);
        $inRow  = $this->events->rawRow($userId, (int) $s['in_id']);
        $outRow = $s['out_id'] !== null ? $this->events->rawRow($userId, (int) $s['out_id']) : null;
        if ($inRow === null) {
            return ['updated' => false, 'error' => 'That session is no longer there.'];
        }
        $inLocal  = (new DateTimeImmutable($inRow['occurred_at'], new DateTimeZone('UTC')))->setTimezone($tz);
        $outLocal = $outRow !== null ? (new DateTimeImmutable($outRow['occurred_at'], new DateTimeZone('UTC')))->setTimezone($tz) : null;

        $newIn  = $startArg !== '' ? $this->parse($startArg, $inLocal->format('Y-m-d'), $tz) : $inLocal;
        $newOut = $endArg !== '' ? $this->parse($endArg, ($outLocal ?? $inLocal)->format('Y-m-d'), $tz) : $outLocal;
        if ($newIn === null || ($endArg !== '' && $newOut === null)) {
            return ['updated' => false, 'error' => 'I could not read that time. Give it like "14:48" or "2026-11-17 14:48".'];
        }
        if ($newOut !== null && $newOut->getTimestamp() <= $newIn->getTimestamp()) {
            return ['updated' => false, 'error' => 'The end must be after the start.'];
        }

        $utc = static fn (DateTimeImmutable $d): string => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $addedOut = null;
        if ($startArg !== '') {
            $this->events->moveEvent($userId, (int) $s['in_id'], $utc($newIn));
        }
        if ($endArg !== '') {
            if ($outRow !== null) {
                $this->events->moveEvent($userId, (int) $s['out_id'], $utc($newOut));
            } else {
                $r = $this->events->add($userId, 'out', $utc($newOut), 'assistant', null, $inRow['location']);
                $addedOut = $r['id'] ?? null;
            }
        }

        $now   = $this->events->sessionOf($userId, (int) $s['in_id']);
        $place = $inRow['location'] ?? 'work';
        $logId = $this->log?->record(
            $userId, 'work_session', (int) $s['in_id'], 'update',
            $place . ' ' . $inLocal->format('j M') . ': ' . $inLocal->format('H:i') . '–' . ($outLocal?->format('H:i') ?? '…') . ' → ' . $newIn->format('H:i') . '–' . ($newOut?->format('H:i') ?? '…'),
            array_values(array_filter([$inRow, $outRow])),
            ['added_out_id' => $addedOut],
            $this->name()
        );
        $summary = $this->events->summary($userId, 'today', $newIn->format('Y-m-d'));

        return [
            'updated'   => true,
            'was'       => ['start' => $inLocal->format('H:i'), 'end' => $outLocal?->format('H:i')],
            'now'       => $now !== null ? ['start' => $now['in'], 'end' => $now['out'], 'minutes' => $now['minutes']] : null,
            'day_total' => $summary['total_label'],
            'change_id' => $logId,
            '_render'   => $summary['card'],
        ];
    }

    private function parse(string $text, string $defaultDay, DateTimeZone $tz): ?DateTimeImmutable
    {
        $t = preg_replace('/\s*(Z|[+-]\d{2}:?\d{2})\s*$/i', '', $text) ?? $text;
        if (preg_match('/^\d{1,2}[:.]\d{2}$/', $t) === 1) {
            $t = $defaultDay . ' ' . str_replace('.', ':', $t);
        }
        try {
            return new DateTimeImmutable($t, $tz);
        } catch (\Exception) {
            return null;
        }
    }
}
