<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turns a Hue CLIP v2 list answer ("data": [ …resources… ]) into plain name -> id entries, and
 * renders one entry as a prompt line. Shared by the conversation memory (ToolMemory) and the
 * saved device list (HomeDevices / HueSync), so both read the Hue answer identically.
 */
final class HueEntries
{
    /** Resource types worth remembering by name. */
    public const TYPES = ['light', 'room', 'zone', 'scene', 'grouped_light', 'device'];

    /**
     * @param array<int, mixed> $items the "data" array of a Hue list answer
     * @return list<array{type:string, name:string, id:string, group:?string}>
     */
    public static function fromItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            $id   = (string) ($item['id'] ?? '');
            $name = trim((string) ($item['metadata']['name'] ?? ''));
            if (!in_array($type, self::TYPES, true) || $id === '' || $name === '' || mb_strlen($name) > 40) {
                continue;
            }
            $group = null;
            // A room/zone is switched through its grouped_light, not its own id.
            if ($type === 'room' || $type === 'zone') {
                foreach ((array) ($item['services'] ?? []) as $svc) {
                    if (is_array($svc) && ($svc['rtype'] ?? '') === 'grouped_light' && isset($svc['rid'])) {
                        $group = (string) $svc['rid'];
                        break;
                    }
                }
            }
            $out[] = ['type' => $type, 'name' => $name, 'id' => $id, 'group' => $group];
        }

        return $out;
    }

    /** @param array{type:string, name:string, id:string, group:?string} $e */
    public static function line(array $e): string
    {
        $line = $e['type'] . ' "' . $e['name'] . '" = ' . $e['id'];
        if (($e['group'] ?? null) !== null) {
            $line .= ' (switch it via grouped_light ' . $e['group'] . ')';
        }

        return $line;
    }

    /** Stable key so a later entry for the same thing replaces an earlier one. */
    public static function key(array $e): string
    {
        return $e['type'] . '|' . mb_strtolower($e['name']);
    }
}
