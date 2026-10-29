<?php

declare(strict_types=1);

namespace App\Energy;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * What a kWh of electricity actually costs the household, hour by hour:
 * (spot + grid tariff + Energinet system tariff + Energinet transmission tariff + electricity tax + supplier markup) x 1.25 VAT.
 * Every rate is read from Energi Data Service, never hardcoded (they change each 1 Jan, grid tariffs more often).
 * Phase 1 is deliberately for one household profile (DK2, Radius "C" customer, negligible supplier markup); the constructor
 * arguments are where per-user settings plug in later. Subscriptions / fixed fees are not part of a per-kWh price.
 */
final class PowerPrices
{
    public const TZ = 'Europe/Copenhagen';

    private const VAT = 1.25;

    /** Energinet's GLN in the price list, and the codes for the per-kWh charges we add. */
    private const ENERGINET_GLN  = '5790000432752';
    private const SYSTEM_TARIFF  = '41000';
    private const TRANSMISSION   = '40000';
    private const ELECTRICITY_TAX = 'EA-001';

    private DateTimeZone $tz;

    public function __construct(
        private EnergiDataService $api,
        private string $area = 'DK2',
        private string $gridGln = '5790000705689',      // Radius Elnet A/S
        private string $gridCode = 'DT_C_01',           // Nettarif C (0.4 kV, households)
        private float $supplierMarkup = 0.0,            // DKK/kWh excl. VAT
    ) {
        $this->tz = new DateTimeZone(self::TZ);
    }

    /**
     * Hourly all-in prices from the hour containing $from up to (excluding) $to; hours with no published spot price are left out
     * (tomorrow's prices appear around 13:00).
     *
     * @return array{hours:list<array<string,mixed>>, incomplete:bool}
     */
    public function hours(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $from = $from->setTimezone($this->tz)->setTime((int) $from->setTimezone($this->tz)->format('G'), 0);
        $to   = $to->setTimezone($this->tz);

        $spot = $this->api->spotPrices($this->area, $from->format('Y-m-d\TH:i'), $to->format('Y-m-d\TH:i'));

        // 15-minute (or hourly) slots -> one average per UTC hour, so a 25-hour autumn day still works.
        $byHour = [];
        foreach ($spot as $row) {
            $utc = new DateTimeImmutable($row['utc'], new DateTimeZone('UTC'));
            $key = $utc->format('Y-m-d\TH');
            $byHour[$key]['t'] = $utc->setTime((int) $utc->format('G'), 0);
            $byHour[$key]['v'][] = $row['dkk_mwh'] / 1000.0;
        }
        ksort($byHour);

        $incomplete = false;
        try {
            $grid = $this->api->priceList($this->gridGln, [$this->gridCode]);
            $ener = $this->api->priceList(self::ENERGINET_GLN, [self::SYSTEM_TARIFF, self::TRANSMISSION, self::ELECTRICITY_TAX]);
        } catch (Throwable $e) {
            $grid = $ener = [];
        }

        $hours = [];
        foreach ($byHour as $h) {
            $local = $h['t']->setTimezone($this->tz);
            $stamp = $local->format('Y-m-d\TH:i:s');
            $slot  = (int) $local->format('G');     // 0..23 -> Price1..Price24

            $spotKwh = array_sum($h['v']) / count($h['v']);
            $parts   = [
                'grid'         => $this->rate($grid, $this->gridCode, $stamp, $slot),
                'energinet'    => ($this->rate($ener, self::SYSTEM_TARIFF, $stamp, $slot) ?? 0.0)
                    + ($this->rate($ener, self::TRANSMISSION, $stamp, $slot) ?? 0.0),
                'tax'          => $this->rate($ener, self::ELECTRICITY_TAX, $stamp, $slot),
            ];
            if ($parts['grid'] === null || $parts['tax'] === null || $parts['energinet'] <= 0.0) {
                $incomplete = true;
            }
            $total = ($spotKwh + ($parts['grid'] ?? 0.0) + $parts['energinet'] + ($parts['tax'] ?? 0.0) + $this->supplierMarkup) * self::VAT;

            $hours[] = [
                'ts'     => $h['t']->getTimestamp(),
                'start'  => $local->format('Y-m-d\TH:i'),
                'hour'   => $slot,
                'total'  => round($total, 3),
                'spot'   => round($spotKwh * self::VAT, 3),                                   // spot incl. VAT, for the breakdown
                'fees'   => round($total - $spotKwh * self::VAT, 3),                          // tariffs + tax + markup incl. VAT
            ];
        }

        return ['hours' => $hours, 'incomplete' => $incomplete];
    }

    /**
     * The cheapest run of $len consecutive hours (lowest average all-in price) among the given hours.
     *
     * @param list<array<string,mixed>> $hours
     *
     * @return array{index:int, length:int, average:float}|null index into $hours
     */
    public static function cheapestWindow(array $hours, int $len): ?array
    {
        $n = count($hours);
        if ($len < 1 || $n < $len) {
            return null;
        }
        $best = null;
        for ($i = 0; $i + $len <= $n; $i++) {
            // Only consecutive hours count (a gap = prices not published in between).
            $ok = true;
            for ($k = 1; $k < $len; $k++) {
                if ((int) $hours[$i + $k]['ts'] - (int) $hours[$i + $k - 1]['ts'] !== 3600) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            $sum = 0.0;
            for ($k = 0; $k < $len; $k++) {
                $sum += (float) $hours[$i + $k]['total'];
            }
            $avg = $sum / $len;
            if ($best === null || $avg < $best['average'] - 1e-9) {
                $best = ['index' => $i, 'length' => $len, 'average' => round($avg, 3)];
            }
        }

        return $best;
    }

    /** @param list<array{code:string, from:string, to:string, prices:list<float>}> $rows */
    private function rate(array $rows, string $code, string $stamp, int $slot): ?float
    {
        foreach ($rows as $r) {
            if ($r['code'] === $code && $r['from'] <= $stamp && $stamp < $r['to']) {
                // A flat charge (system tariff, tax) only fills Price1; an hourly one fills all 24.
                return $this->isFlat($r['prices']) ? $r['prices'][0] : $r['prices'][$slot];
            }
        }

        return null;
    }

    /** @param list<float> $p */
    private function isFlat(array $p): bool
    {
        return array_sum(array_slice($p, 1)) == 0.0;
    }
}
