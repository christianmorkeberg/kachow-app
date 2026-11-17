<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Cash;
use App\Data\CashEntries;
use App\Data\ChangeLog;
use App\Data\WorkEvents;
use DateTimeImmutable;
use DateTimeZone;

/** Tool: put back something the assistant changed or deleted (an id from get_change_log): a deleted work session or cash movement, or the earlier times/amounts of an edit. */
final class RestoreChange implements Tool
{
    public function __construct(private ChangeLog $log, private WorkEvents $events, private CashEntries $cash, private Cash $position)
    {
    }

    public function name(): string
    {
        return 'restore_change';
    }

    public function description(): string
    {
        return 'Undoes ONE logged change by its id from get_change_log: brings back a deleted work session or cash movement, or sets an edited one back to how it was. '
            . 'Check with get_change_log first so the right id is used; say plainly what was restored. A change can be restored once.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'The change id (from get_change_log).']],
            'required'   => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $c = $this->log->find($userId, (int) ($arguments['id'] ?? 0));
        if ($c === null) {
            return ['restored' => false, 'error' => 'No such change (check get_change_log; or the log is not set up yet).'];
        }
        if ($c['restored_at'] !== null) {
            return ['restored' => false, 'error' => 'That change was already restored.'];
        }
        $before = is_array($c['before']) ? $c['before'] : [];
        $out    = null;
        if ($c['entity'] === 'work_session') {
            $out = $this->work($userId, $c, $before);
        } elseif ($c['entity'] === 'cash_movement') {
            $out = $this->cash($userId, $c, $before);
        }
        if ($out === null) {
            return ['restored' => false, 'error' => 'That kind of change cannot be restored.'];
        }
        $this->log->markRestored($userId, (int) $c['id']);

        return ['restored' => true, 'what' => $c['summary']] + $out;
    }

    /** @param array<string, mixed> $c @param list<array<string, mixed>> $rows @return array<string, mixed>|null */
    private function work(int $userId, array $c, array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }
        if ($c['action'] === 'delete') {
            foreach ($rows as $r) {
                $this->events->insertRaw($userId, $r);
            }
        } else {
            foreach ($rows as $r) {
                $this->events->moveEvent($userId, (int) $r['id'], (string) $r['occurred_at']);
            }
            $added = (int) (($c['after']['added_out_id'] ?? 0));
            if ($added > 0) {
                $this->events->delete($userId, $added);   // the clock-out the edit had added
            }
        }
        $day = (new DateTimeImmutable((string) $rows[0]['occurred_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(WorkEvents::LOCAL_TZ))->format('Y-m-d');
        $sum = $this->events->summary($userId, 'today', $day);

        return ['day_total' => $sum['total_label'], '_render' => $sum['card']];
    }

    /** @param array<string, mixed> $c @param array<string, mixed> $m @return array<string, mixed>|null */
    private function cash(int $userId, array $c, array $m): ?array
    {
        if ($m === [] || !isset($m['amount'])) {
            return null;
        }
        if ($c['action'] === 'delete') {
            $this->cash->add($userId, (string) $m['direction'], (float) $m['amount'], (string) $m['category'], (string) ($m['note'] ?? ''), (string) $m['date']);
        } else {
            $this->cash->update($userId, (int) $c['entity_id'], ['direction' => $m['direction'], 'amount' => $m['amount'], 'category' => $m['category'], 'note' => $m['note'] ?? '', 'date' => $m['date']]);
        }
        $card = $this->position->position($userId);

        return ['expected' => $card['expected'], '_render' => $card];
    }
}
