<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Colours for Hue lamps. The bridge takes a colour as CIE xy (`color.xy`) or a white as a mirek colour temperature
 * (`color_temperature.mirek`, 153 cold … 500 warm); people say "red", "#ff8800" or "2700 K". This turns the one into the other, and
 * a light's xy back into a hex for the card's colour swatch. Pure.
 */
final class HueColor
{
    public const MIREK_MIN = 153;
    public const MIREK_MAX = 500;

    /** @var array<string, string> colour word (English and Danish) → hex */
    private const NAMES = [
        'red' => 'ff0000', 'rød' => 'ff0000', 'roed' => 'ff0000', 'green' => '00c800', 'grøn' => '00c800', 'groen' => '00c800',
        'blue' => '0000ff', 'blå' => '0000ff', 'blaa' => '0000ff', 'yellow' => 'ffe000', 'gul' => 'ffe000', 'orange' => 'ff7800',
        'purple' => '8000ff', 'lilla' => '8000ff', 'violet' => '8000ff', 'pink' => 'ff50a0', 'lyserød' => 'ff50a0', 'lyserod' => 'ff50a0',
        'cyan' => '00e0ff', 'turkis' => '00e0ff', 'turquoise' => '00e0ff', 'magenta' => 'ff00ff', 'lime' => '80ff00',
    ];

    /** @var array<string, int> white words → mirek */
    private const WHITES = [
        'warm white' => 400, 'warm' => 400, 'varm hvid' => 400, 'varm' => 400, 'candle' => 500, 'candlelight' => 500, 'lys' => 366,
        'white' => 300, 'hvid' => 300, 'neutral white' => 300, 'neutral' => 300, 'cool white' => 233, 'cool' => 233, 'kold hvid' => 233, 'kold' => 233,
        'daylight' => 200, 'dagslys' => 200, 'cold white' => 180,
    ];

    /**
     * What to PUT to a light for a colour, or null if it is not understood.
     *
     * @return array{color?: array{xy: array{x: float, y: float}}, color_temperature?: array{mirek: int}}|null
     */
    public static function body(string $spec): ?array
    {
        $s = mb_strtolower(trim($spec));
        if ($s === '') {
            return null;
        }
        if (isset(self::WHITES[$s])) {
            return ['color_temperature' => ['mirek' => self::WHITES[$s]]];
        }
        if (preg_match('/^(\d{4,5})\s*k$/', $s, $m) === 1) {
            return ['color_temperature' => ['mirek' => self::mirekFromKelvin((int) $m[1])]];
        }
        $hex = self::NAMES[$s] ?? null;
        if ($hex === null && preg_match('/^#?([0-9a-f]{6})$/', $s, $m) === 1) {
            $hex = $m[1];
        } elseif ($hex === null && preg_match('/^#?([0-9a-f]{3})$/', $s, $m) === 1) {
            $hex = $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if ($hex === null) {
            return null;
        }
        [$x, $y] = self::hexToXy($hex);

        return ['color' => ['xy' => ['x' => $x, 'y' => $y]]];
    }

    public static function mirekFromKelvin(int $kelvin): int
    {
        return max(self::MIREK_MIN, min(self::MIREK_MAX, (int) round(1_000_000 / max(1, $kelvin))));
    }

    /** @return array{0: float, 1: float} CIE xy of an sRGB colour (Philips' wide-gamut conversion) */
    public static function hexToXy(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $c = [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
        [$r, $g, $b] = array_map(static fn (float $v): float => $v > 0.04045 ? (($v + 0.055) / 1.055) ** 2.4 : $v / 12.92, $c);
        $X = $r * 0.664511 + $g * 0.154324 + $b * 0.162028;
        $Y = $r * 0.283881 + $g * 0.668433 + $b * 0.047685;
        $Z = $r * 0.000088 + $g * 0.072310 + $b * 0.986039;
        $sum = $X + $Y + $Z;
        if ($sum <= 0.0) {
            return [0.3127, 0.3290];     // black has no colour: the white point
        }

        return [round($X / $sum, 4), round($Y / $sum, 4)];
    }

    /** A hex swatch for a light's xy (full brightness: the brightness is shown by the slider). */
    public static function xyToHex(float $x, float $y): string
    {
        if ($y <= 0.0) {
            return '#ffffff';
        }
        $Y = 1.0;
        $X = $Y / $y * $x;
        $Z = $Y / $y * (1.0 - $x - $y);
        $r = $X * 1.656492 - $Y * 0.354851 - $Z * 0.255038;
        $g = -$X * 0.707196 + $Y * 1.655397 + $Z * 0.036152;
        $b = $X * 0.051713 - $Y * 0.121364 + $Z * 1.011530;
        $r = max(0.0, $r); $g = max(0.0, $g); $b = max(0.0, $b);
        $max = max($r, $g, $b);
        if ($max > 1.0) {
            $r /= $max; $g /= $max; $b /= $max;
        }
        $gamma = static fn (float $v): int => (int) round(255 * ($v <= 0.0031308 ? 12.92 * $v : 1.055 * ($v ** (1 / 2.4)) - 0.055));

        return sprintf('#%02x%02x%02x', $gamma($r), $gamma($g), $gamma($b));
    }

    /** The swatch colour for a mirek white (warm = amber, cold = bluish), for the card. */
    public static function mirekToHex(int $mirek): string
    {
        $t = ($mirek - self::MIREK_MIN) / (self::MIREK_MAX - self::MIREK_MIN);   // 0 cold … 1 warm
        $r = 255;
        $g = (int) round(255 - 70 * $t);
        $b = (int) round(255 - 150 * $t);
        $r = (int) round(235 + 20 * $t);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
