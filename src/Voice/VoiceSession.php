<?php

declare(strict_types=1);

namespace App\Voice;

use App\Assistant\AssistantLoop;
use App\Tools\ToolRegistry;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What a voice session is told when it starts: the model, the voice, the system instruction (how to talk, how to
 * use tools naturally, the local time, what the assistant knows about the user, today's agenda) and the tools.
 * The browser sends this as the first message of the Live connection.
 */
final class VoiceSession
{
    public const DEFAULT_MODEL = 'gemini-3.8-live';
    public const DEFAULT_VOICE = 'Puck';

    /**
     * The user's voice limits from their settings, clamped.
     *
     * @return array{daily_seconds:int, idle_seconds:int}
     */
    public static function limits(?string $dailyMinutes, ?string $idleSeconds): array
    {
        $min  = max(1, min(120, is_numeric($dailyMinutes) ? (int) $dailyMinutes : 10));
        $idle = max(15, min(300, is_numeric($idleSeconds) ? (int) $idleSeconds : 45));

        return ['daily_seconds' => $min * 60, 'idle_seconds' => $idle];
    }

    /**
     * @param list<array{content:string}> $memories
     * @param list<array{instruction:string}> $instructions
     * @return array<string, mixed> the `setup` object
     */
    public static function setup(
        ToolRegistry $registry,
        string $model,
        string $voice,
        string $toolMode,
        array $memories = [],
        array $instructions = [],
        string $agenda = '',
        ?DateTimeImmutable $now = null,
        string $language = 'auto',
        array $stopWords = [],
        string $routines = '',
    ): array {
        $language = self::language($language);
        $speech   = ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice !== '' ? $voice : self::DEFAULT_VOICE]]];
        if ($language !== 'auto') {
            $speech['languageCode'] = $language === 'da' ? 'da-DK' : 'en-US';   // pins the accent too, so the voice stays the same
        }

        return [
            'model'                    => 'models/' . ($model !== '' ? $model : self::DEFAULT_MODEL),
            'generationConfig'         => [
                'responseModalities' => ['AUDIO'],
                'speechConfig'       => $speech,
            ],
            'systemInstruction'        => ['parts' => [['text' => self::prompt($memories, $instructions, $agenda, $now, $language, $stopWords, $routines)]]],
            'tools'                    => [['functionDeclarations' => VoiceTools::declarations($registry, $toolMode)]],
            'inputAudioTranscription'  => new \stdClass(),
            'outputAudioTranscription' => new \stdClass(),
            'sessionResumption'        => new \stdClass(),
            'contextWindowCompression' => ['slidingWindow' => new \stdClass()],
        ];
    }

    /**
     * The language a session uses: the saved choice ('en' / 'da'), else — while nothing was ever chosen — Danish if the
     * browser says Danish, otherwise English. (The saved value 'auto' only means "not chosen yet".)
     */
    public static function resolveLanguage(?string $stored, string $browserHint = ''): string
    {
        $stored = self::language($stored);
        if ($stored !== 'auto') {
            return $stored;
        }

        return str_starts_with(strtolower(trim($browserHint)), 'da') ? 'da' : 'en';
    }

    /**
     * The words that end a voice conversation, from the voice_stop_words setting: comma / newline separated, lower-cased,
     * unique, at most 20, each at most 40 characters.
     *
     * @return list<string>
     */
    public static function stopWords(?string $setting): array
    {
        $out = [];
        foreach (preg_split('/[,\n;]+/', mb_strtolower((string) $setting)) ?: [] as $w) {
            $w = trim(preg_replace('/\s+/u', ' ', $w) ?? $w);
            if ($w !== '' && mb_strlen($w) <= 40 && !in_array($w, $out, true)) {
                $out[] = $w;
            }
            if (count($out) >= 20) {
                break;
            }
        }

        return $out;
    }

    /** @param list<string> $words */
    public static function stopWordsRule(array $words): string
    {
        $list = $words === [] ? '' : ' (for example: "' . implode('", "', $words) . '")';

        return 'ENDING THE CONVERSATION: when the user says goodbye or wants to stop talking' . $list . ', say ONE very short goodbye in their language '
            . 'and then call ' . VoiceTools::END . '. Only then — never end it on your own, and never because of a word inside a longer request.';
    }

    /** 'auto' (English or Danish, following the user), 'en' or 'da'. */
    public static function language(?string $v): string
    {
        $v = strtolower(trim((string) $v));

        return in_array($v, ['en', 'da'], true) ? $v : 'auto';
    }

    /**
     * @param list<array{content:string}> $memories
     * @param list<array{instruction:string}> $instructions
     */
    public static function prompt(array $memories, array $instructions, string $agenda, ?DateTimeImmutable $now = null, string $language = 'auto', array $stopWords = [], string $routines = ''): string
    {
        $lang = match (self::language($language)) {
            'en'    => 'LANGUAGE: always speak English, whatever language the user uses.',
            'da'    => 'LANGUAGE: always speak Danish (dansk), whatever language the user uses.',
            default => 'LANGUAGE: you speak ONLY English or Danish. Reply in the language the user just spoke (Danish → Danish, English → English); '
                . 'if unsure, use English. Never use any other language — unclear audio is still English or Danish.',
        };
        $p = "You are Kachow, the user's personal assistant, and you are talking to them out loud. " . $lang . ' Keep the same voice, accent and tone '
            . "throughout the whole conversation.\n\n"
            . "HOW TO SPEAK: short, natural, relaxed sentences, like a helpful person — no lists, no markdown, no reading out long details. "
            . "Anything long (an agenda, a table, a list) is shown on the user's screen as a card: give the gist in a sentence or two. "
            . "Say times and dates the way people say them (\"Thursday at ten\", not \"2026-10-22T10:00\").\n\n"
            . "HOW TO ACT: when the request is clear, just do it with a tool — don't ask for permission for ordinary, reversible things. "
            . "Before a tool call give a SHORT lead-in in the progressive (\"Moving it to Thursday…\", \"Adding milk…\") — never claim something "
            . "is done before the tool result says so. When the result comes back, confirm in a few words what happened, with the concrete new "
            . "value (\"…done, it's Thursday at ten\"). If the result is an error, say in one short sentence that it didn't work and, if the "
            . "reason is clear, why — do not retry over and over. If a result looks wrong or empty, say so; never invent a result.\n"
            . "Tools marked SLOW take a while: say something like \"On it — I'll tell you when it's done\", carry on talking normally, and when "
            . "the result arrives tell the user briefly.\n"
            . "When one request needs several tool calls (e.g. several lights), make them together and confirm ONCE at the end, "
            . "in one short sentence — never one confirmation per call, and don't repeat what you already said.\n"
            . "If the user changes their mind or corrects you mid-action, set the new value explicitly (an absolute date or time), don't "
            . "shift relative to the old one.\n"
            . "Resolve \"this event\", \"it\", \"that one\" from the agenda below and what you just talked about; ask one short question only "
            . "if it is really ambiguous. Tools take LOCAL times (Europe/Copenhagen) — use the local time stated below.\n"
            . "Use " . VoiceTools::GATEWAY . " for anything the other tools cannot do. To DELETE or clear something use the matching tool: it will "
            . "not do it on the first call but tell you to ask — then ask ONE short question naming exactly what goes (\"Delete the potato "
            . "event at five?\"), and only on a clear yes call it again with the same arguments. For deleting other kinds of things use "
            . VoiceTools::GATEWAY . " and relay its question.\n\n"
            . self::stopWordsRule($stopWords) . "\n\n"
            . AssistantLoop::nowLine($now);

        if ($agenda !== '') {
            $p .= "\n\nTHE USER'S AGENDA (next 7 days; event id and calendar id are for the calendar tools — never read them out):\n" . $agenda;
        }
        if ($routines !== '') {
            $p .= "\n\nTHE USER'S ROUTINES. When what they say matches a routine's phrase or name, call " . 'run_routine' . " (it starts in the background and ends with "
                . "a notification — say \"On it\" in a few words) instead of doing its steps yourself:\n" . $routines;
        }
        if ($instructions !== []) {
            $p .= "\n\nThe user's standing instructions (always follow):";
            foreach ($instructions as $i) {
                $p .= "\n- " . trim((string) $i['instruction']);
            }
        }
        if ($memories !== []) {
            $p .= "\n\nWhat you already know about the user (use it to help; don't recite it):";
            foreach (array_slice($memories, 0, 60) as $m) {
                $p .= "\n- " . trim((string) $m['content']);
            }
        }

        return $p;
    }

    /**
     * Compact agenda lines from get_calendar_events' events.
     *
     * @param list<array<string, mixed>> $events
     */
    public static function agenda(array $events, int $max = 40): string
    {
        $tz    = new DateTimeZone('Europe/Copenhagen');
        $lines = [];
        foreach (array_slice($events, 0, $max) as $e) {
            $start = (string) ($e['start'] ?? '');
            $end   = (string) ($e['end'] ?? '');
            if ($start === '') {
                continue;
            }
            try {
                $s = new DateTimeImmutable($start);
                if (!empty($e['allDay'])) {
                    $when = $s->format('D j M') . ' (all day)';
                } else {
                    $s    = $s->setTimezone($tz);
                    $when = $s->format('D j M H:i');
                    if ($end !== '') {
                        $when .= '–' . (new DateTimeImmutable($end))->setTimezone($tz)->format('H:i');
                    }
                }
            } catch (\Throwable $ex) {
                $when = $start;
            }
            $cal      = (string) ($e['calendar_id'] ?? '');
            $lines[]  = '- ' . $when . ' · ' . trim((string) ($e['summary'] ?? '(no title)'))
                . ' [id ' . (string) ($e['id'] ?? '?') . ($cal !== '' ? ', calendar ' . $cal : '') . ']';
        }

        return implode("\n", $lines);
    }
}
