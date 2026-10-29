<?php

declare(strict_types=1);

namespace App\Energy;

use RuntimeException;

/**
 * Thin client for Energi Data Service (Energinet's open API, api.energidataservice.dk) — no key needed.
 * Two datasets: DayAheadPrices (spot price per price area, 15-minute steps since 2025-10-01) and
 * DatahubPricelist (grid / Energinet tariffs and the electricity tax, with validity periods).
 */
final class EnergiDataService
{
    private const BASE = 'https://api.energidataservice.dk/dataset/';

    /** @var callable(string):array{0:int,1:string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? [$this, 'curlGet'];
    }

    /**
     * Spot price rows in [startDk, endDk) — both Danish local "Y-m-d\TH:i", end exclusive. Oldest first.
     *
     * @return list<array{utc:string, dkk_mwh:float}> utc = "Y-m-d\TH:i:s" in UTC
     */
    public function spotPrices(string $area, string $startDk, string $endDk): array
    {
        $data = $this->getJson('DayAheadPrices', [
            'start'   => $startDk,
            'end'     => $endDk,
            'filter'  => json_encode(['PriceArea' => [$area]], JSON_THROW_ON_ERROR),
            'sort'    => 'TimeUTC asc',
            'columns' => 'TimeUTC,DayAheadPriceDKK',
            'limit'   => '500',
        ]);
        $rows = [];
        foreach ($data['records'] ?? [] as $r) {
            if (is_array($r) && isset($r['TimeUTC'], $r['DayAheadPriceDKK']) && is_numeric($r['DayAheadPriceDKK'])) {
                $rows[] = ['utc' => (string) $r['TimeUTC'], 'dkk_mwh' => (float) $r['DayAheadPriceDKK']];
            }
        }

        return $rows;
    }

    /**
     * Price-list rows (tariffs / taxes) for one charge owner and a set of charge-type codes. Prices are DKK per kWh
     * (Price1..Price24 = hour of day, Danish local time) excluding VAT.
     *
     * @param list<string> $codes
     *
     * @return list<array{code:string, from:string, to:string, prices:list<float>}> from/to = Danish local "Y-m-d\TH:i:s"
     */
    public function priceList(string $gln, array $codes): array
    {
        $data = $this->getJson('DatahubPricelist', [
            'filter' => json_encode(['GLN_Number' => [$gln], 'ChargeTypeCode' => $codes], JSON_THROW_ON_ERROR),
            'sort'   => 'ValidFrom desc',
            'limit'  => '60',
        ]);
        $rows = [];
        foreach ($data['records'] ?? [] as $r) {
            if (!is_array($r) || !isset($r['ChargeTypeCode'], $r['ValidFrom'])) {
                continue;
            }
            $prices = [];
            for ($h = 1; $h <= 24; $h++) {
                $prices[] = isset($r['Price' . $h]) && is_numeric($r['Price' . $h]) ? (float) $r['Price' . $h] : 0.0;
            }
            $rows[] = [
                'code'   => (string) $r['ChargeTypeCode'],
                'from'   => (string) $r['ValidFrom'],
                'to'     => (string) ($r['ValidTo'] ?? '9999-12-31T00:00:00'),
                'prices' => $prices,
            ];
        }

        return $rows;
    }

    /** @param array<string, string> $query @return array<string, mixed> */
    private function getJson(string $dataset, array $query): array
    {
        [$status, $body] = ($this->transport)(self::BASE . $dataset . '?' . http_build_query($query));
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data)) {
            throw new RuntimeException('Energi Data Service ' . $dataset . ' failed (HTTP ' . $status . ')');
        }

        return $data;
    }

    /** @return array{0:int,1:string} */
    private function curlGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Energi Data Service request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }
}
