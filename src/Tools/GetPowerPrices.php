<?php

declare(strict_types=1);

namespace App\Tools;

use App\Energy\PowerPrices;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Tool: what electricity costs per kWh, hour by hour (spot price + grid and Energinet tariffs + electricity tax + VAT), for today,
 * tomorrow, a date, or "from now on" — with the cheapest stretch for a job of N hours (dishwasher, washing machine) if asked.
 * Read-only; renders the 'power_prices' card. Phase 1 knows one household profile (see PowerPrices).
 */
final class GetPowerPrices implements Tool
{
    private const MAX_WINDOW = 12;

    public function __construct(private PowerPrices $prices, private ?DateTimeImmutable $now = null)
    {
    }

    public function name(): string
    {
        return 'get_power_prices';
    }

    public function description(): string
    {
        return 'Gets the ALL-IN electricity price per kWh (kr incl. VAT: spot price + grid tariff + Energinet tariffs + '
            . 'electricity tax), hour by hour — right now, tonight, tomorrow (published around 13:00) or a given date. '
            . 'Use it for "what does power cost", "is electricity expensive now", "when is it cheapest", and to pick a time for '
            . 'the dishwasher / washing machine / car charging: pass window_hours (how long the job runs) and optionally finish_by '
            . '("07:00" = the next 07:00) to get the cheapest run of consecutive hours that ends in time. Without day it covers '
            . 'from now until the end of the latest published prices. It shows a price card — answer briefly (the price now, the '
            . 'cheapest window and when) and never re-list the hours. Prices are per kWh, not per job: the job\'s own '
            . 'consumption is not known yet, so do not invent a cost in kr for a job.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'day'          => ['type' => 'string', 'description' => '"today", "tomorrow" or a date YYYY-MM-DD for a whole-day view. Omit for "from now on".'],
                'window_hours' => ['type' => 'integer', 'description' => 'Find the cheapest run of this many consecutive hours (1-' . self::MAX_WINDOW . '), e.g. 2 for a dishwasher.'],
                'finish_by'    => ['type' => 'string', 'description' => 'The job must be finished by this time: "HH:MM" (next occurrence) or "YYYY-MM-DD HH:MM". Only with window_hours.'],
            ],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $tz  = new DateTimeZone(PowerPrices::TZ);
        $now = ($this->now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);

        $day = strtolower(trim((string) ($arguments['day'] ?? '')));
        if ($day === '') {
            $from  = $now->setTime((int) $now->format('G'), 0);
            $to    = $now->setTime(0, 0)->add(new DateInterval('P2D'));
            $label = 'now';
        } else {
            $date = match ($day) {
                'today'    => $now->setTime(0, 0),
                'tomorrow' => $now->setTime(0, 0)->add(new DateInterval('P1D')),
                default    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $day, $tz) : false,
            };
            if ($date === false) {
                return ['error' => 'day must be "today", "tomorrow" or a date like 2026-10-31.'];
            }
            $from  = $date;
            $to    = $date->add(new DateInterval('P1D'));
            $label = $date->format('Y-m-d');
        }

        try {
            $res = $this->prices->hours($from, $to);
        } catch (Throwable $e) {
            return ['error' => 'I couldn\'t get the electricity prices right now (Energi Data Service did not answer). Try again shortly.'];
        }
        $hours = $res['hours'];
        if ($hours === []) {
            return ['error' => 'No prices are published for that period yet (tomorrow\'s appear around 13:00).'];
        }

        // Window search: only hours that have not started yet, and that end by finish_by if given.
        $windowHours = (int) ($arguments['window_hours'] ?? 0);
        $window      = null;
        $finishBy    = null;
        if ($windowHours > 0) {
            $windowHours = min($windowHours, self::MAX_WINDOW);
            $finishBy    = $this->parseFinishBy((string) ($arguments['finish_by'] ?? ''), $now, $tz);
            if ($finishBy === false) {
                return ['error' => 'finish_by must be "HH:MM" or "YYYY-MM-DD HH:MM".'];
            }
            $nowTs = $now->setTime((int) $now->format('G'), 0)->getTimestamp();
            $cand  = array_values(array_filter($hours, static fn (array $h): bool =>
                (int) $h['ts'] >= $nowTs && ($finishBy === null || (int) $h['ts'] + 3600 <= $finishBy->getTimestamp())));
            $best = PowerPrices::cheapestWindow($cand, $windowHours);
            if ($best !== null) {
                $first  = $cand[$best['index']];
                $last   = $cand[$best['index'] + $windowHours - 1];
                $window = [
                    'start' => $first['start'],
                    'end'   => (new DateTimeImmutable('@' . ((int) $last['ts'] + 3600)))->setTimezone($tz)->format('Y-m-d\TH:i'),
                    'hours' => $windowHours,
                    'avg'   => $best['average'],
                    'first_ts' => (int) $first['ts'],
                ];
            }
        }

        $totals = array_map(static fn (array $h): float => (float) $h['total'], $hours);
        $avg    = round(array_sum($totals) / count($totals), 3);
        $nowTs  = $now->setTime((int) $now->format('G'), 0)->getTimestamp();
        $nowIdx = null;
        foreach ($hours as $i => $h) {
            if ((int) $h['ts'] === $nowTs) {
                $nowIdx = $i;
            }
        }
        $sorted = $hours;
        usort($sorted, static fn (array $a, array $b): int => $a['total'] <=> $b['total']);
        $cheapest  = array_slice($sorted, 0, 3);
        $dearest   = end($sorted);

        $fmt = static fn (array $h): array => ['from' => $h['start'], 'kr_per_kwh' => $h['total']];

        $out = [
            'period'          => $label,
            'unit'            => 'kr/kWh incl. VAT, all-in (spot + tariffs + tax)',
            'average'         => $avg,
            'price_now'       => $nowIdx !== null ? $hours[$nowIdx]['total'] : null,
            'cheapest_hours'  => array_map($fmt, $cheapest),
            'dearest_hour'    => $fmt($dearest),
            'hourly'          => array_map(static fn (array $h): array => [$h['start'], $h['total']], $hours),
            'covers'          => $hours[0]['start'] . ' to ' . end($hours)['start'] . ' (hour starts)',
        ];
        if ($windowHours > 0) {
            $out['cheapest_window'] = $window === null
                ? 'No ' . $windowHours . '-hour stretch fits in the published prices' . ($finishBy ? ' before ' . $finishBy->format('Y-m-d H:i') : '') . '.'
                : ['start' => $window['start'], 'end' => $window['end'], 'hours' => $windowHours, 'avg_kr_per_kwh' => $window['avg']]
                    + ($finishBy ? ['finish_by' => $finishBy->format('Y-m-d H:i')] : []);
        }
        if ($res['incomplete']) {
            $out['warning'] = 'A tariff could not be read, so these prices may be too low — say so.';
        }

        $out['_render'] = [
            'kind'       => 'power_prices',
            'title'      => $label === 'now' ? 'Electricity price' : 'Electricity price · ' . $label,
            'unit'       => 'kr/kWh',
            'area'       => 'DK2',
            'hours'      => array_map(static fn (array $h): array => ['s' => $h['start'], 't' => $h['total'], 'sp' => $h['spot']], $hours),
            'now_index'  => $nowIdx,
            'avg'        => $avg,
            'window'     => $window === null ? null : [
                'from' => (int) array_search($window['first_ts'], array_map(static fn (array $h): int => (int) $h['ts'], $hours), true),
                'len'  => $windowHours,
                'avg'  => $window['avg'],
                'start' => $window['start'],
                'end'   => $window['end'],
            ],
            'incomplete' => $res['incomplete'],
        ];

        return $out;
    }

    /** null = no limit; false = unparseable. */
    private function parseFinishBy(string $raw, DateTimeImmutable $now, DateTimeZone $tz): DateTimeImmutable|false|null
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $raw, $m) === 1) {
            $t = $now->setTime((int) $m[1], (int) $m[2]);

            return $t <= $now ? $t->add(new DateInterval('P1D')) : $t;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', str_replace('T', ' ', $raw), $tz);

        return $d === false ? false : $d;
    }
}
