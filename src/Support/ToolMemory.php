<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What the assistant CREATED or CHANGED earlier in this conversation, as a short digest for
 * the system prompt.
 *
 * Tool exchanges are stored in `messages` but not replayed as functionCall/functionResponse
 * pairs (that would need the original args persisted, plus the thoughtSignature thinking
 * models require). The cost of that simplification showed up in report #31: the id of a
 * freshly created income draft was visible in the turn that made it and gone in the next, so
 * "I rounded up to 70 hours" could not find the draft — and a second one was created for the
 * same invoice.
 *
 * This replays just the part later turns actually need: the identity and outcome of each
 * write, a line each, newest last. Small scalars from a whitelist only — no cards, no
 * coordinates, no free text that could grow unbounded.
 */
final class ToolMemory
{
    /** Keys worth remembering: what it was, what happened to it, and how to name it again. */
    private const KEEP = [
        // identity — the whole point: ids to pass back to update_*/delete_* tools
        'id', 'ids', 'event_ids', 'place_id', 'destination_id', 'invoice_id', 'conversation',
        // outcome
        'status', 'created', 'updated', 'recorded', 'saved', 'deleted', 'duplicate', 'booked', 'confirmed',
        // labels, so the model can tell two drafts apart
        'name', 'place', 'customer', 'title', 'job', 'doc_number', 'date', 'day', 'period', 'list',
        // sizes, so a correction can be recognised as one
        'total', 'amount_ex_vat', 'km', 'minutes', 'hours', 'at',
    ];

    /** Keys that alone prove the row was a WRITE (a read result would not carry them). */
    private const WRITE_MARKERS = ['id', 'ids', 'event_ids', 'created', 'updated', 'recorded', 'saved', 'deleted'];

    private const MAX_ENTRIES   = 10;
    private const MAX_LINE_CHAR = 150;
    private const MAX_CHARS     = 1400;

    /**
     * @param list<array<string, mixed>> $rows stored message rows (ASC), as Conversations::messages returns
     */
    public static function digest(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            if (($row['role'] ?? '') !== 'tool') {
                continue;
            }
            $tool = trim((string) ($row['tool_name'] ?? ''));
            $data = json_decode((string) ($row['content'] ?? ''), true);
            if ($tool === '' || !is_array($data) || isset($data['error'])) {
                continue;
            }
            $line = self::line($tool, $data);
            if ($line === null) {
                continue;
            }
            // A later write to the same thing supersedes the earlier one, so the model never
            // sees two conflicting versions of the same id.
            unset($lines[self::key($tool, $data)]);
            $lines[self::key($tool, $data)] = $line;
        }
        if ($lines === []) {
            return '';
        }

        $lines = array_slice(array_values($lines), -self::MAX_ENTRIES);
        $out   = '';
        foreach ($lines as $line) {
            if (mb_strlen($out) + mb_strlen($line) > self::MAX_CHARS) {
                break;
            }
            $out .= '- ' . $line . "\n";
        }
        if (trim($out) === '') {
            return '';
        }

        return "What you already created or changed EARLIER IN THIS CONVERSATION (tool results from "
            . "previous turns — the ids are real and you can pass them straight to an update/delete tool; "
            . "they may have been changed since, and the user may have edited things on a card):\n"
            . rtrim($out, "\n")
            . "\nWhen the user corrects something you just did (\"make it 70 hours\", \"wrong customer\"), "
            . 'UPDATE that id — never create a second entry for the same thing. If no id here matches what '
            . 'they mean, look it up with a read tool before writing.';
    }

    /**
     * Whether this conversation has already used home_device_call — so a follow-up that names nothing
     * ("on again", "a bit dimmer") is still about the home and needs the ids too.
     *
     * @param list<array<string, mixed>> $rows stored message rows (ASC)
     */
    public static function homeActive(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['role'] ?? '') === 'tool' && ($row['tool_name'] ?? '') === 'home_device_call') {
                return true;
            }
        }

        return false;
    }

    private const HOME_MAX_ENTRIES = 60;
    private const HOME_MAX_CHARS   = 4000;

    /**
     * Home-device ids (Hue lights, rooms, scenes) the assistant already knows, as a prompt block.
     * Without this a follow-up like "turn it on again" had no id to use (reads are not replayed), so
     * the model guessed one, got a 404, re-listed everything, and only then sent the real command:
     * three tool calls and four model calls for one light. With the ids in the prompt it is one call.
     *
     * Two sources: $base is the SAVED list (HomeDevices — refreshed daily / on demand), and the
     * lists looked up earlier in THIS conversation, which are newer and win for the same name.
     *
     * @param list<array<string, mixed>>                                          $rows stored message rows (ASC)
     * @param list<array{type:string, name:string, id:string, group:?string}> $base saved entries
     */
    public static function homeDevices(array $rows, array $base = []): string
    {
        $found = [];
        foreach ($base as $e) {
            $found[HueEntries::key($e)] = HueEntries::line($e);
        }
        foreach ($rows as $row) {
            if (($row['role'] ?? '') !== 'tool' || ($row['tool_name'] ?? '') !== 'home_device_call') {
                continue;
            }
            $data  = json_decode((string) ($row['content'] ?? ''), true);
            $items = is_array($data) ? ($data['response']['data'] ?? null) : null;
            if (!is_array($items) || !array_is_list($items)) {
                continue;
            }
            foreach (HueEntries::fromItems($items) as $e) {
                unset($found[HueEntries::key($e)]);
                $found[HueEntries::key($e)] = HueEntries::line($e);
            }
        }
        if ($found === []) {
            return '';
        }

        $out = '';
        foreach (array_slice(array_values($found), -self::HOME_MAX_ENTRIES) as $line) {
            if (mb_strlen($out) + mb_strlen($line) > self::HOME_MAX_CHARS) {
                break;
            }
            $out .= '- ' . $line . "\n";
        }
        if ($out === '') {
            return '';
        }

        return "Home device ids you ALREADY KNOW (looked up earlier — real Hue ids: pass them straight to "
            . "home_device_call; do NOT guess an id and do NOT re-list everything first). Only look things up "
            . "again if a call returns 404 or the user says something was added or renamed:\n"
            . rtrim($out, "\n");
    }

    /** One digest line, or null when the row carries nothing identifying. */
    private static function line(string $tool, array $data): ?string
    {
        $parts = [];
        foreach (self::KEEP as $k) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $v = self::scalar($data[$k]);
            if ($v === null) {
                continue;
            }
            $parts[] = $k === 'id' ? 'id ' . $v : $k . ': ' . $v;
        }
        if ($parts === [] || !self::isWrite($data)) {
            return null;
        }

        return mb_substr($tool . ' → ' . implode(', ', $parts), 0, self::MAX_LINE_CHAR);
    }

    private static function isWrite(array $data): bool
    {
        foreach (self::WRITE_MARKERS as $k) {
            if (array_key_exists($k, $data)) {
                return true;
            }
        }

        return false;
    }

    /** Small scalars only: anything nested, long or empty is left out. */
    private static function scalar(mixed $v): ?string
    {
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            $v = trim($v);

            return $v === '' || mb_strlen($v) > 40 ? null : $v;
        }
        if (is_array($v) && $v !== [] && count($v) <= 6) {
            $flat = [];
            foreach ($v as $item) {
                if (!is_int($item) && !is_string($item) && !is_float($item)) {
                    return null;
                }
                $flat[] = (string) $item;
            }

            return mb_substr('[' . implode(',', $flat) . ']', 0, 40);
        }

        return null;
    }

    /** Identity of the thing written, so a later write to it replaces this line. */
    private static function key(string $tool, array $data): string
    {
        foreach (['id', 'ids', 'event_ids'] as $k) {
            if (isset($data[$k])) {
                return $tool . '#' . (is_array($data[$k]) ? implode(',', $data[$k]) : (string) $data[$k]);
            }
        }

        return $tool . '#' . md5((string) json_encode($data));
    }
}
