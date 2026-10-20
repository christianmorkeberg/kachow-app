<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A routine started by a sensor ("when the kitchen light level drops below 50", "when the window is opened"). Anything that can
 * make a web request can be a sensor — a Pico, a Shelly, a phone shortcut — and reports `sensor` (a name the user chooses) and
 * `value` to api/sensor.php. The routine fires when its condition BECOMES true (was false, now true), never while it stays
 * true, and the very first reading is only a baseline — the same rules the place triggers use. Pure.
 */
final class SensorTrigger
{
    /** @return string lower-case, letters / digits / underscores: "Kitchen light" and "kitchen_light" are the same sensor */
    public static function key(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(strtr(trim($name), ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa']))) ?? '', '_');
    }

    /**
     * Cleans a sensor trigger from a model: sensor name, op below | above | is, value (a number or a word like "open").
     *
     * @param array<string, mixed> $in
     * @return array{sensor:string, op:string, value:float|string}|null null if it names no sensor or no value
     */
    public static function normalize(array $in): ?array
    {
        $sensor = self::key((string) ($in['sensor'] ?? ''));
        $value  = $in['value'] ?? null;
        if ($sensor === '' || $value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $op = in_array($in['op'] ?? '', ['below', 'above', 'is'], true) ? (string) $in['op'] : 'is';
        if (is_numeric($value)) {
            $value = (float) $value;
        } else {
            $value = mb_strtolower(trim((string) $value));
            if ($op !== 'is') {
                return null;   // "below open" means nothing
            }
        }

        return ['sensor' => $sensor, 'op' => $op, 'value' => $value];
    }

    /** Whether a reading satisfies the trigger's condition. @param array<string, mixed> $cfg @param float|int|string $reading */
    public static function matches(array $cfg, $reading): bool
    {
        $want = $cfg['value'] ?? null;
        $op   = (string) ($cfg['op'] ?? 'is');
        if (is_numeric($reading) && is_numeric($want)) {
            return match ($op) {
                'below' => (float) $reading < (float) $want,
                'above' => (float) $reading > (float) $want,
                default => abs((float) $reading - (float) $want) < 1e-9,
            };
        }

        return $op === 'is' && is_string($want) && mb_strtolower(trim((string) $reading)) === $want;
    }

    /** "Kitchen light below 50" / "Window is open" for the card and the model. @param array<string, mixed> $cfg */
    public static function describe(array $cfg): string
    {
        $name  = ucfirst(str_replace('_', ' ', (string) ($cfg['sensor'] ?? 'sensor')));
        $value = is_float($cfg['value'] ?? null) ? rtrim(rtrim(number_format((float) $cfg['value'], 2, '.', ''), '0'), '.') : (string) ($cfg['value'] ?? '');

        return $name . ' ' . match ($cfg['op'] ?? 'is') { 'below' => 'below ', 'above' => 'above ', default => 'is ' } . $value;
    }
}
