<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shapes a home device's JSON answer for the model. A Hue v2 list ("data": [ …lights… ]) is very
 * verbose — a light is ~1.5 KB, mostly colour gamuts, effect menus and firmware info the model
 * never needs — so a plain character cut-off silently dropped the LAST lights of a longer list
 * (a light "missing"). This strips that noise at every depth, and if the list STILL doesn't fit it
 * keeps whole items (never half an item) and says exactly how many were left out.
 */
final class HomeResponse
{
    /** Keys that bloat the answer and never help decide what to do. */
    private const NOISE_KEYS = [
        'product_data', 'dynamics', 'powerup', 'identify', 'signaling', 'effects', 'effects_v2',
        'timed_effects', 'gradient', 'color_temperature_delta', 'gamut', 'gamut_type', 'mirek_schema',
        'mirek_valid', 'palette', 'speed', 'auto_dynamic', 'light_level_report', 'temperature_report',
        'motion_report',
    ];

    /**
     * @param array<string, mixed> $decoded a device's decoded JSON answer
     * @return array{response: mixed, item_count?: int, shown?: int, truncated?: bool, note?: string}
     */
    public static function compact(array $decoded, int $maxChars): array
    {
        $isList = isset($decoded['data']) && is_array($decoded['data']) && array_is_list($decoded['data']);
        $count  = $isList ? count($decoded['data']) : null;

        $slim = self::strip($decoded);
        if (self::size($slim) <= $maxChars) {
            return ['response' => $slim] + ($count !== null ? ['item_count' => $count] : []);
        }

        if (!$isList) {
            return [
                'response'  => mb_substr((string) json_encode($slim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, $maxChars),
                'truncated' => true,
                'note'      => 'The answer was cut off at ' . $maxChars . ' characters — ask for a narrower resource (e.g. one id).',
            ];
        }

        // Keep as many WHOLE items as fit.
        $items = $slim['data'];
        $kept  = [];
        foreach ($items as $item) {
            $candidate = $slim;
            $candidate['data'] = array_merge($kept, [$item]);
            if (self::size($candidate) > $maxChars) {
                break;
            }
            $kept[] = $item;
        }
        $slim['data'] = $kept;

        return [
            'response'   => $slim,
            'item_count' => $count,
            'shown'      => count($kept),
            'truncated'  => true,
            'note'       => 'Only ' . count($kept) . ' of ' . $count . ' items fit. Do NOT claim this is the full list — '
                . 'fetch the rest one at a time by id, or ask the user which ones matter.',
        ];
    }

    /** @param mixed $v */
    private static function strip($v)
    {
        if (!is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $val) {
            if (is_string($k) && in_array($k, self::NOISE_KEYS, true)) {
                continue;
            }
            $out[$k] = self::strip($val);
        }

        return $out;
    }

    /** @param mixed $v */
    private static function size($v): int
    {
        return strlen((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
