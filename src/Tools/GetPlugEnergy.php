<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\PowerReadings;
use App\Energy\PowerPrices;
use App\Energy\Tuya;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Tool: how much energy a metering smart plug has used over a period (today, yesterday, the last 7 days, this month, or a date), the
 * average and peak load, and what it cost at the real hourly all-in price. Worked out from the readings taken every 5 minutes, so it
 * only covers the time since they started and says so.
 */
final class GetPlugEnergy implements Tool
{
    public function __construct(private PowerReadings $store, private ?PowerPrices $prices = null, private ?DateTimeImmutable $now = null)
    {
    }

    public function name(): string
    {
        return 'get_plug_energy';
    }

    public function description(): string
    {
        return 'Energy used by a smart plug that measures power, over a period: kWh, average and peak watts, and the cost in kr at the actual hourly '
            . 'electricity price (spot + tariffs + tax + VAT). Use it for "how much power did the freezer use this week", "what did the washing '
            . 'machine cost today". period is today, yesterday, week (last 7 days), month (this calendar month) or a date. It is built from '
            . 'readings taken every 5 minutes since monitoring started: say how much of the period is covered when it is not all of it, and '
            . 'never extrapolate. For the draw right now use get_plug_power.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'plug'   => ['type' => 'string', 'description' => 'Part of the plug\'s name. Omit when there is only one.'],
                'period' => ['type' => 'string', 'description' => '"today", "yesterday", "week", "month" or a date YYYY-MM-DD. Default today.'],
            ],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if ($userId !== Tuya::ownerUserId()) {
            return ['error' => 'No smart plug is connected for you yet.'];
        }
        $tz  = new DateTimeZone(PowerPrices::TZ);
        $now = ($this->now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
        $day = $now->setTime(0, 0);
        $p   = strtolower(trim((string) ($arguments['period'] ?? 'today'))) ?: 'today';
        [$from, $to, $label] = match (true) {
            $p === 'today'     => [$day, $now, 'today'],
            $p === 'yesterday' => [$day->modify('-1 day'), $day, 'yesterday'],
            $p === 'week'      => [$now->modify('-7 days'), $now, 'the last 7 days'],
            $p === 'month'     => [$day->modify('first day of this month'), $now, 'this month'],
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $p) => [new DateTimeImmutable($p . ' 00:00', $tz), (new DateTimeImmutable($p . ' 00:00', $tz))->modify('+1 day'), $p],
            default            => [$day, $now, 'today'],
        };
        if ($to > $now) {
            $to = $now;
        }

        try {
            $plugs = $this->store->plugs($userId);
        } catch (Throwable $e) {
            return ['error' => 'Power readings are not set up yet (the table is missing).'];
        }
        $want = mb_strtolower(trim((string) ($arguments['plug'] ?? '')));
        $plugs = array_values(array_filter($plugs, static fn (array $x): bool => $want === '' || str_contains(mb_strtolower($x['name']), $want)));
        if ($plugs === []) {
            return ['error' => 'No readings from a plug' . ($want !== '' ? ' called that' : '') . ' yet. Monitoring records one every 5 minutes once it is set up.'];
        }

        $prices = null;
        try {
            $prices = $this->prices?->hours($from, $to);
        } catch (Throwable) {
            $prices = null;
        }
        $byTs = [];
        foreach ($prices['hours'] ?? [] as $h) {
            $byTs[(int) $h['ts']] = (float) $h['total'];
        }

        $out    = [];
        $window = max(1, $to->getTimestamp() - $from->getTimestamp());
        foreach ($plugs as $plug) {
            $e = $this->store->energy($userId, $plug['device_id'], $from, $to);
            $cost = null;
            $missing = 0;
            if ($byTs !== []) {
                $cost = 0.0;
                foreach ($e['hours'] as $ts => $kwh) {
                    if (isset($byTs[$ts])) {
                        $cost += $kwh * $byTs[$ts];
                    } else {
                        $missing++;
                    }
                }
            }
            $out[] = [
                'plug'          => $plug['name'],
                'kwh'           => round($e['kwh'], 3),
                'avg_watts'     => $e['avg_watts'],
                'peak_watts'    => $e['peak_watts'],
                'cost_kr'       => $cost === null ? null : round($cost, 2),
                'cost_partial'  => $cost !== null && $missing > 0,
                'covered_share' => round(min(1.0, $e['covered_seconds'] / $window), 2),
                'readings'      => $e['readings'],
            ];
        }

        return [
            'period' => $label, 'plugs' => $out,
            'note'   => 'Worked out from readings every 5 minutes; covered_share is how much of the period has data (gaps are left out, never guessed). '
                . 'Cost uses the real all-in hourly price (kr incl. VAT) and leaves out hours without a published price.',
        ];
    }
}
