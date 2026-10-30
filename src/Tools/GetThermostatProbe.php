<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ThermostatSamples;
use App\Support\ThermostatProbe;

/**
 * Tool: what the thermostat probe has logged — how often each valve really reports, how stale a reading is, how fast the
 * temperature falls. For judging whether an open window can be detected from the valves.
 */
final class GetThermostatProbe implements Tool
{
    public function __construct(private ThermostatSamples $samples)
    {
    }

    public function name(): string
    {
        return 'get_thermostat_probe';
    }

    public function description(): string
    {
        return 'Reads the thermostat probe\'s log (an experiment that samples every radiator valve once a minute): per valve, how many distinct '
            . 'reports there were, the minutes between reports (min / median / max), how old a reading was when read, how often the temperature '
            . 'changed, and the fastest fall. Use for "how often do the thermostats report", "can we detect an open window", "what did the thermostat probe find". '
            . '`hours` = how far back (default 24, max 336); `rows` > 0 also returns the newest N raw samples. If there are no samples, the probe '
            . 'is off: it is the system task "Thermostat probe" (resume it with update_system_task; it also needs a cPanel entry every minute).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'hours' => ['type' => 'integer', 'description' => 'How many hours back to look (default 24).'],
                'rows'  => ['type' => 'integer', 'description' => 'Also return the newest N raw samples (default 0, max 60).'],
            ],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $hours = max(1, min(336, (int) ($arguments['hours'] ?? 24)));
        $rows  = $this->samples->since($userId, $hours);
        if ($rows === []) {
            return ['samples' => 0, 'note' => 'No samples in the last ' . $hours . ' hours — the "Thermostat probe" system task is paused (or has not run). Resume it, and add the cPanel cron entry that runs bin/thermostat-probe-cron.php every minute.'];
        }
        $out = ['hours' => $hours, 'samples' => count($rows), 'valves' => ThermostatProbe::summary($rows)];
        // The model sees at most 60 raw rows; an export (export_data sets _export) gets the newest 5000 — a full day of three valves is about 4,300.
        $n = !empty($arguments['_export']) ? 5000 : max(0, min(60, (int) ($arguments['rows'] ?? 0)));
        if ($n > 0) {
            $out['newest'] = array_map(static fn (array $r): array => array_filter($r, static fn ($v): bool => $v !== null), array_slice($rows, -$n));
        }

        return $out;
    }
}
