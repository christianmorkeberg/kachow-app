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
            . 'convert to UTC or add a "Z"; the app handles the timezone. Omit "at" for right now. To '
            . 'clock out a session, log an "out" at the time they left. If the user has multiple '
            . 'workplaces, pass "place" (the same label used for that workplace) so it pairs with the '
            . 'right session. Use get_work_hours first if you need to see current state.';
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

        try {
            $res = $this->events->add($userId, $kind, $atUtc, 'assistant', $note, $place);
        } catch (\Exception $e) {
            return ['error' => 'I could not read that time. Give it as a clear date/time.'];
        }

        // Show the freshly-updated day so the user sees the effect.
        $summary = $this->events->summary($userId, 'today');

        return [
            'recorded'      => $res['status'] === 'ok',
            'duplicate'     => $res['status'] === 'duplicate',
            'kind'          => $res['kind'],
            'at'            => $res['local'],
            'total_today'   => $summary['total_label'],
            'clocked_in'    => $summary['ongoing'],
            '_render'       => $summary['card'],
        ];
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
