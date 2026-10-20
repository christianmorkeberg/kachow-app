<?php

declare(strict_types=1);

namespace App\Notify;

use App\Data\Routines;
use App\Support\BackgroundRun;
use App\Support\SensorTrigger;

/**
 * Starts sensor routines as readings arrive (api/sensor.php). Like RoutinePlaceRunner it only REQUESTS the run — conditions,
 * "ask first" and the run itself are the runner's — and a routine fires when its condition becomes true after being false;
 * the first reading is only a baseline. A user with no sensor routines costs one query.
 */
final class RoutineSensorRunner
{
    /** @var (callable(): void)|null */
    private $kick;

    /** @param (callable(): void)|null $kick starts the runner in the background; default: bin/scheduled-tasks-cron.php --requested-only */
    public function __construct(private Routines $routines, ?callable $kick = null)
    {
        $this->kick = $kick;
    }

    /**
     * @param float|int|string $value
     * @return list<int> ids of the routines a run was requested for
     */
    public function onReading(int $userId, string $sensor, $value): array
    {
        $key = SensorTrigger::key($sensor);
        if ($key === '') {
            return [];
        }
        $requested = [];
        foreach ($this->routines->active($userId, 'sensor') as $r) {
            if (($r['trigger']['sensor'] ?? '') !== $key) {
                continue;
            }
            $now  = SensorTrigger::matches($r['trigger'], $value);
            $prev = $this->routines->sensorState($r['id']);
            $this->routines->saveSensorState($r['id'], $now, (string) $value);
            if ($prev === false && $now && $this->routines->requestRun($userId, $r['id'], 'sensor: ' . SensorTrigger::describe($r['trigger']))) {
                $requested[] = $r['id'];
            }
        }
        if ($requested !== []) {
            $kick = $this->kick ?? static fn () => BackgroundRun::kick(dirname(__DIR__, 2) . '/bin/scheduled-tasks-cron.php', ['--requested-only']);
            $kick();
        }

        return $requested;
    }
}
