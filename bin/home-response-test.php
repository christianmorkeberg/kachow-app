<?php

declare(strict_types=1);

/**
 * HomeResponse unit tests — pure logic: php bin/home-response-test.php
 * Regression for "a Hue light is missing": a long, verbose light list used to be cut off
 * at a character limit, silently dropping the last lights.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\HomeResponse;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

/** A light shaped like Hue CLIP v2's: lots of bulk around the few fields that matter. */
function hueLight(int $n): array
{
    return [
        'id'       => sprintf('aaaaaaaa-0000-4000-8000-%012d', $n),
        'id_v1'    => '/lights/' . $n,
        'owner'    => ['rid' => sprintf('bbbbbbbb-0000-4000-8000-%012d', $n), 'rtype' => 'device'],
        'metadata' => ['name' => 'Lampe ' . $n, 'archetype' => 'sultan_bulb'],
        'on'       => ['on' => $n % 2 === 0],
        'dimming'  => ['brightness' => 50.0, 'min_dim_level' => 0.2],
        'color_temperature' => ['mirek' => 366, 'mirek_valid' => true,
            'mirek_schema' => ['mirek_minimum' => 153, 'mirek_maximum' => 500]],
        'color'    => ['xy' => ['x' => 0.45, 'y' => 0.41],
            'gamut' => ['red' => ['x' => 0.6915, 'y' => 0.3083], 'green' => ['x' => 0.17, 'y' => 0.7],
                        'blue' => ['x' => 0.1532, 'y' => 0.0475]], 'gamut_type' => 'C'],
        'dynamics' => ['status' => 'none', 'status_values' => ['none', 'dynamic_palette'], 'speed' => 0.0, 'speed_valid' => false],
        'effects'  => ['status_values' => ['no_effect', 'candle', 'fire'], 'status' => 'no_effect', 'effect_values' => ['no_effect', 'candle', 'fire']],
        'powerup'  => ['preset' => 'safety', 'configured' => true, 'on' => ['mode' => 'on', 'on' => ['on' => true]]],
        'signaling' => ['signal_values' => ['no_signal', 'on_off', 'on_off_color', 'alternating']],
        'product_data' => ['model_id' => 'LCT015', 'manufacturer_name' => 'Signify', 'product_name' => 'Hue color lamp'],
        'type'     => 'light',
    ];
}
function envelope(int $n): array
{
    return ['errors' => [], 'data' => array_map('hueLight', range(1, $n))];
}
$rawSize = static fn (array $a): int => strlen((string) json_encode($a));

echo "1) the noise is stripped but what the model needs survives\n";
$r = HomeResponse::compact(envelope(1), 20000);
$light = $r['response']['data'][0];
check('keeps the name, on/off and brightness', $light['metadata']['name'] === 'Lampe 1' && isset($light['on']['on']) && $light['dimming']['brightness'] === 50.0);
check('keeps id, owner and type (to map rooms/devices)', isset($light['id'], $light['owner']['rid']) && $light['type'] === 'light');
check('keeps the colour xy but drops the gamut', isset($light['color']['xy']) && !isset($light['color']['gamut']));
check('drops product_data / dynamics / effects / powerup / signaling',
    !isset($light['product_data']) && !isset($light['dynamics']) && !isset($light['effects']) && !isset($light['powerup']) && !isset($light['signaling']));

echo "2) REGRESSION: a long light list is no longer cut short\n";
$full = envelope(14);
check('the RAW 14-light list exceeds the old 12000-character limit', $rawSize($full) > 12000, (string) $rawSize($full));
$r = HomeResponse::compact($full, 12000);
check('after stripping, all 14 lights fit in that same old budget', count($r['response']['data']) === 14 && empty($r['truncated']), json_encode(array_diff_key($r, ['response' => 1])));
check('item_count reports 14 so the model can state the real number', $r['item_count'] === 14);
check('the LAST light is present (it used to be the one that vanished)', $r['response']['data'][13]['metadata']['name'] === 'Lampe 14');

echo "3) too big even when stripped: whole items only, and it says what was left out\n";
$r = HomeResponse::compact(envelope(40), 6000);
check('flagged as truncated', ($r['truncated'] ?? false) === true);
check('item_count is the true total (40)', $r['item_count'] === 40);
check('shown matches the number of items actually returned', $r['shown'] === count($r['response']['data']) && $r['shown'] < 40);
check('never returns a half item (every shown item is complete)', !array_filter($r['response']['data'], static fn (array $i): bool => !isset($i['metadata']['name'], $i['id'])));
check('stays within the budget', strlen((string) json_encode($r['response'])) <= 6000);
check('the note warns not to call it the full list', str_contains($r['note'], 'Do NOT claim'));

echo "4) a non-list answer passes through untouched\n";
$r = HomeResponse::compact(['errors' => [], 'data' => [['id' => 'x', 'metadata' => ['name' => 'Stue']]]], 20000);
check('a single small item is returned as-is with item_count 1', $r['item_count'] === 1 && $r['response']['data'][0]['metadata']['name'] === 'Stue');
$r = HomeResponse::compact(['ok' => true, 'version' => '1'], 20000);
check('a plain object (e.g. the pico ping) is returned unchanged, no count', $r['response'] === ['ok' => true, 'version' => '1'] && !isset($r['item_count']));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
