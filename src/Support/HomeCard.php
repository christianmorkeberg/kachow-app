<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The lights half of the Home card, from the bridge's own lists (CLIP v2 light, room, grouped_light): rooms with their lights and
 * on / brightness / colour state, and the bodies the card's controls send back. Pure.
 */
final class HomeCard
{
    /**
     * @param list<array<string, mixed>> $lights  GET /clip/v2/resource/light "data"
     * @param list<array<string, mixed>> $rooms   GET /clip/v2/resource/room "data"
     * @param list<array<string, mixed>> $groups  GET /clip/v2/resource/grouped_light "data"
     * @return array{rooms: list<array<string, mixed>>, other: list<array<string, mixed>>}
     */
    public static function lights(array $lights, array $rooms, array $groups): array
    {
        $byDevice = [];
        $info = [];
        foreach ($lights as $l) {
            if (!is_array($l) || ($l['id'] ?? '') === '') {
                continue;
            }
            $entry = self::light($l);
            $info[(string) $l['id']] = $entry;
            $device = (string) ($l['owner']['rid'] ?? '');
            if ($device !== '') {
                $byDevice[$device] = (string) $l['id'];
            }
        }
        $groupById = [];
        foreach ($groups as $g) {
            if (is_array($g) && ($g['id'] ?? '') !== '') {
                $groupById[(string) $g['id']] = $g;
            }
        }

        $out = [];
        $placed = [];
        foreach ($rooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $members = [];
            foreach ((array) ($room['children'] ?? []) as $child) {
                $lid = $byDevice[(string) ($child['rid'] ?? '')] ?? null;
                if ($lid !== null) {
                    $members[] = $info[$lid];
                    $placed[$lid] = true;
                }
            }
            $gid = '';
            foreach ((array) ($room['services'] ?? []) as $svc) {
                if (is_array($svc) && ($svc['rtype'] ?? '') === 'grouped_light') {
                    $gid = (string) $svc['rid'];
                    break;
                }
            }
            $g = $groupById[$gid] ?? [];
            $anyOn = false;
            $bris = [];
            foreach ($members as $m) {
                $anyOn = $anyOn || $m['on'];
                if ($m['on'] && $m['brightness'] !== null) {
                    $bris[] = $m['brightness'];
                }
            }
            $out[] = [
                'name'       => (string) ($room['metadata']['name'] ?? 'Room'),
                'group_id'   => $gid !== '' ? $gid : null,
                'on'         => isset($g['on']['on']) ? (bool) $g['on']['on'] : $anyOn,
                'brightness' => isset($g['dimming']['brightness']) ? (int) round((float) $g['dimming']['brightness']) : ($bris !== [] ? (int) round(array_sum($bris) / count($bris)) : null),
                'lights'     => $members,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        $other = array_values(array_filter($info, static fn (array $l): bool => !isset($placed[$l['id']])));
        usort($other, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['rooms' => $out, 'other' => $other];
    }

    /**
     * @param array<string, mixed> $l one light of the bridge's list
     * @return array{id:string, name:string, on:bool, brightness:?int, dim:bool, color:bool, ct:bool, hex:?string}
     */
    public static function light(array $l): array
    {
        $hex = null;
        if (isset($l['color']['xy']['x'], $l['color']['xy']['y']) && !isset($l['color_temperature']['mirek'])) {
            $hex = HueColor::xyToHex((float) $l['color']['xy']['x'], (float) $l['color']['xy']['y']);
        } elseif (isset($l['color_temperature']['mirek']) && is_numeric($l['color_temperature']['mirek'])) {
            $hex = HueColor::mirekToHex((int) $l['color_temperature']['mirek']);
        }

        return [
            'id'         => (string) $l['id'],
            'name'       => (string) ($l['metadata']['name'] ?? 'Light'),
            'on'         => (bool) ($l['on']['on'] ?? false),
            'brightness' => isset($l['dimming']['brightness']) ? (int) round((float) $l['dimming']['brightness']) : null,
            'dim'        => isset($l['dimming']),
            'color'      => isset($l['color']),
            'ct'         => isset($l['color_temperature']),
            'hex'        => $hex,
        ];
    }

    /**
     * The PUT body for a control on the card: on, brightness 0–100, colour (a hex, a word, a white). Null if there is nothing valid to send.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>|null
     */
    public static function body(array $in): ?array
    {
        $b = [];
        if (array_key_exists('on', $in)) {
            $b['on'] = ['on' => filter_var($in['on'], FILTER_VALIDATE_BOOLEAN)];
        }
        if (isset($in['brightness']) && is_numeric($in['brightness'])) {
            $bri = max(0.0, min(100.0, (float) $in['brightness']));
            if ($bri > 0.0) {
                $b['dimming'] = ['brightness' => $bri];
            } else {
                $b['on'] = ['on' => false];
            }
        }
        if (isset($in['mirek']) && is_numeric($in['mirek'])) {
            $b['color_temperature'] = ['mirek' => max(HueColor::MIREK_MIN, min(HueColor::MIREK_MAX, (int) round((float) $in['mirek'])))];
            $b['on'] = ['on' => true];
        }
        if (isset($in['color']) && is_string($in['color']) && trim($in['color']) !== '') {
            $c = HueColor::body($in['color']);
            if ($c === null) {
                return null;
            }
            $b += $c;
            $b['on'] = ['on' => true];     // choosing a colour on a light that is off turns it on
        }

        return $b === [] ? null : $b;
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $id) === 1;
    }
}
