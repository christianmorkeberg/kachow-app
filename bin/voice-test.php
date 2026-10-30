<?php

declare(strict_types=1);

/**
 * Voice mode (Gemini Live) server side: tool allow-list and declarations, the session setup message, token minting,
 * the single-tool runner, the event log + daily cap, and the auto-report of a failed call. In-memory SQLite, no network:
 *   php -d extension=php_pdo_sqlite bin/voice-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\FeedbackReports;
use App\Data\Users;
use App\Data\VoiceLog;
use App\Tools\GetVoiceLog;
use App\Tools\Tool;
use App\Tools\ToolRegistry;
use App\Voice\ConfirmGate;
use App\Voice\KioskVoice;
use App\Voice\LiveTokens;
use App\Voice\VoiceAlert;
use App\Voice\VoiceSession;
use App\Voice\VoiceToolRunner;
use App\Voice\VoiceTools;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/voice-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

/** A fake tool: returns what $fn gives. */
function fakeTool(string $name, callable $fn, string $desc = 'does a thing'): Tool
{
    return new class ($name, $fn, $desc) implements Tool {
        public function __construct(private string $n, private $fn, private string $d) {}
        public function name(): string { return $this->n; }
        public function description(): string { return $this->d; }
        public function parameters(): array { return ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'required' => []]; }
        public function execute(array $arguments, int $userId): array { return ($this->fn)($arguments, $userId); }
    };
}

$registry = new ToolRegistry();
foreach (array_merge(VoiceTools::FAST, ['get_current_weather', 'home_device_call', 'delete_calendar_event', 'clear_checked_items', 'get_emails']) as $n) {
    $registry->register(fakeTool($n, fn ($a, $u) => ['ok' => true, 'name' => $n]));
}

echo "1) the allow-list and the declarations\n";
check('fast and slow tools are allowed, the gateway too', VoiceTools::isAllowed('update_calendar_event') && VoiceTools::isAllowed('home_device_call') && VoiceTools::isAllowed(VoiceTools::GATEWAY));
check('deletes are allowed but flagged for confirmation; everything else is not', VoiceTools::isAllowed('delete_calendar_event') && VoiceTools::needsConfirm('delete_calendar_event')
    && !VoiceTools::needsConfirm('update_calendar_event') && !VoiceTools::isAllowed('get_emails') && !VoiceTools::isAllowed('forget_about_me'));
$decl = VoiceTools::declarations($registry);
$names = array_column($decl, 'name');
check('only allowed tools are declared, plus the gateway', in_array('delete_calendar_event', $names, true) && !in_array('get_emails', $names, true)
    && in_array('update_calendar_event', $names, true) && in_array(VoiceTools::GATEWAY, $names, true));
$by = array_column($decl, null, 'name');
check('auto: fast tools block, slow tools are NON_BLOCKING', $by['update_calendar_event']['behavior'] === 'BLOCKING' && $by['get_current_weather']['behavior'] === 'NON_BLOCKING' && $by['home_device_call']['behavior'] === 'BLOCKING'
    && $by[VoiceTools::GATEWAY]['behavior'] === 'NON_BLOCKING');
check('slow tools say SLOW in their description, fast ones do not', str_starts_with($by['get_current_weather']['description'], 'SLOW') && !str_starts_with($by['update_calendar_event']['description'], 'SLOW')
    && str_starts_with($by[VoiceTools::GATEWAY]['description'], 'SLOW'));
$forTools = static fn (string $mode): array => array_values(array_unique(array_column(array_filter(VoiceTools::declarations($registry, $mode), static fn (array $d): bool => $d['name'] !== VoiceTools::END), 'behavior')));
check('the other modes make every server tool the same (end_conversation always blocks)', $forTools('nonblocking') === ['NON_BLOCKING'] && $forTools('blocking') === ['BLOCKING']
    && VoiceTools::endDeclaration()['behavior'] === 'BLOCKING');
check('each tool declaration has name, description, parameters', isset($by['set_reminder']['parameters']['type']) && $by['set_reminder']['description'] !== '');

echo "2) the session setup\n";
$agenda = VoiceSession::agenda([
    ['id' => 'ev1', 'calendar_id' => 'primary', 'summary' => 'Dentist', 'start' => '2026-10-22T08:00:00+02:00', 'end' => '2026-10-22T09:00:00+02:00', 'allDay' => false],
    ['id' => 'ev2', 'calendar_id' => 'work', 'summary' => 'Offsite', 'start' => '2026-10-23', 'end' => '2026-10-24', 'allDay' => true],
]);
check('agenda lines: local time, title, the ids the tools need', str_contains($agenda, '- Thu 22 Oct 08:00–09:00 · Dentist [id ev1, calendar primary]') && str_contains($agenda, 'Fri 23 Oct (all day) · Offsite [id ev2, calendar work]'), $agenda);
$setup = VoiceSession::setup($registry, 'gemini-3.8-live', 'Kore', 'auto', [['content' => 'Likes oat milk']], [['instruction' => 'Be brief']], $agenda, new DateTimeImmutable('2026-10-19 16:25:00 UTC'));
$json = json_decode(json_encode($setup), true);
check('model, audio output and the voice', $json['model'] === 'models/gemini-3.8-live' && $json['generationConfig']['responseModalities'] === ['AUDIO']
    && $json['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore');
check('transcription, resumption and compression are switched on as {} objects', str_contains(json_encode($setup), '"inputAudioTranscription":{}') && str_contains(json_encode($setup), '"outputAudioTranscription":{}')
    && str_contains(json_encode($setup), '"sessionResumption":{}') && str_contains(json_encode($setup), '"slidingWindow":{}'));
check('the tools are in functionDeclarations', isset($json['tools'][0]['functionDeclarations']) && count($json['tools'][0]['functionDeclarations']) === count($decl));
$text = $json['systemInstruction']['parts'][0]['text'];
check('the prompt: lead-in rule, never claim before the result, SLOW rule', str_contains($text, 'SHORT lead-in') && str_contains($text, 'never claim something is done before the tool result') && str_contains($text, 'Tools marked SLOW'));
check('the prompt has the local time, the agenda, instructions and memories', str_contains($text, 'Monday 2026-10-19 18:25') && str_contains($text, 'Dentist') && str_contains($text, 'Be brief') && str_contains($text, 'Likes oat milk'));
check('no agenda section when there is none', !str_contains(VoiceSession::prompt([], [], ''), 'AGENDA'));
check('an empty model/voice falls back to the defaults', json_decode(json_encode(VoiceSession::setup($registry, '', '', 'auto')), true)['model'] === 'models/gemini-3.8-live'
    && VoiceSession::DEFAULT_VOICE === 'Puck');
check('limits are clamped', VoiceSession::limits('10', '45') === ['daily_seconds' => 600, 'idle_seconds' => 45] && VoiceSession::limits('0', '5') === ['daily_seconds' => 60, 'idle_seconds' => 15]
    && VoiceSession::limits('999', '9999') === ['daily_seconds' => 7200, 'idle_seconds' => 300] && VoiceSession::limits(null, 'x') === ['daily_seconds' => 600, 'idle_seconds' => 45]);

echo "3) the token\n";
$seen = [];
$ok = new LiveTokens('KEY123', function (string $url, array $h, string $body) use (&$seen): array { $seen = [$url, $h, json_decode($body, true)]; return [200, '{"name":"auth_tokens/abc123"}']; });
$t = $ok->mint(900, 90, new DateTimeImmutable('2026-10-19 12:00:00 UTC'));
check('returns the token name and when it expires', $t['token'] === 'auth_tokens/abc123' && str_starts_with($t['expires_at'], '2026-10-19T12:15:00'));
check('POSTs to the v1alpha auth_tokens endpoint with the key in a header, never in the URL', $seen[0] === 'https://generativelanguage.googleapis.com/v1alpha/auth_tokens'
    && in_array('x-goog-api-key: KEY123', $seen[1], true) && !str_contains($seen[0], 'KEY123'));
check('single use, short life, must start within 90 s', $seen[2]['uses'] === 1 && $seen[2]['expireTime'] === '2026-10-19T12:15:00Z' && $seen[2]['newSessionExpireTime'] === '2026-10-19T12:01:30Z');
$refused = new LiveTokens('K', fn () => [403, '{"error":{"message":"API key not valid"}}']);
try { $refused->mint(); check('a refusal throws with Google\'s own message', false); } catch (RuntimeException $e) { check('a refusal throws with Google\'s own message', str_contains($e->getMessage(), 'HTTP 403') && str_contains($e->getMessage(), 'API key not valid')); }
$noName = new LiveTokens('K', fn () => [200, '{}']);
try { $noName->mint(); check('a reply without a token is an error', false); } catch (RuntimeException $e) { check('a reply without a token is an error', true); }
check('the browser WebSocket URL is the constrained v1alpha endpoint', str_ends_with(LiveTokens::WS, 'google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContentConstrained'));

echo "4) running one tool call\n";
$registry->register(fakeTool('get_calendar_events', fn ($a, $u) => ['count' => 2, 'events' => array_map(fn ($i) => ['id' => "e$i", 'summary' => str_repeat('x', 700)], range(1, 60)), '_render' => ['kind' => 'calendar'], '_persist_strip' => ['x']]));
$registry->register(fakeTool('set_reminder', fn ($a, $u) => ['error' => 'No time given.']));
$registry->register(fakeTool('log_workout', fn ($a, $u) => throw new RuntimeException('db down')));
$gw = function (string $req): array { return ['reply' => 'Done: ' . $req, 'card' => ['kind' => 'notice']]; };
$run = new VoiceToolRunner($registry, $gw);
$r = $run->run('get_calendar_events', [], 1);
check('a good call: ok, the card is split off, screen-only keys are gone', $r['ok'] && $r['card'] === ['kind' => 'calendar'] && !isset($r['result']['_render']) && !isset($r['result']['_persist_strip']));
check('long lists and strings are trimmed for the model', count($r['result']['events']) === 40 && $r['result']['events_note'] === 'showing 40 of 60' && mb_strlen($r['result']['events'][0]['summary']) <= 601);
$r = $run->run('set_reminder', [], 1);
check('a tool error is flagged but still handed to the model — and is not a fault', $r['ok'] === false && $r['error'] === 'No time given.' && $r['result']['error'] === 'No time given.' && $r['fault'] === false);
$r = $run->run('log_workout', [], 1);
check('an exception becomes a friendly error, with the real one logged — and is a fault', $r['ok'] === false && $r['result']['error'] === 'The tool failed.' && str_contains($r['error'], 'db down') && $r['fault'] === true);
$r = $run->run('get_emails', [], 1);
check('a tool outside the allow-list is refused (logged, not a fault)', $r['ok'] === false && str_contains($r['error'], 'not allowed') && $r['fault'] === false);
$r = $run->run(VoiceTools::GATEWAY, ['request' => 'what is my balance'], 1);
check('the gateway asks the full assistant and returns its words and card', $r['ok'] && $r['result'] === ['answer' => 'Done: what is my balance'] && $r['card'] === ['kind' => 'notice']);
check('an empty gateway request is refused', $run->run(VoiceTools::GATEWAY, [], 1)['ok'] === false);
check('it measures time', is_int($r['ms']) && $r['ms'] >= 0);

echo "5) the voice log and the daily cap\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE voice_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, session_id TEXT, conversation_id INT, kind TEXT, name TEXT, ok INT, ms INT, detail TEXT, created_at TEXT)");
$log = new VoiceLog($db);
$log->add(1, 'sess1aaaa', 'session_start', 77);
$log->add(1, 'sess1aaaa', 'first_audio', 77, null, null, 640);
$log->add(1, 'sess1aaaa', 'tool_call', 77, 'update_calendar_event', true, 950, ['args' => '{"event_id":"e1"}']);
$log->add(1, 'sess1aaaa', 'tool_error', 77, 'set_reminder', false, 120, ['error' => 'No time given.']);
$log->add(1, 'sess1aaaa', 'interrupted');
$log->add(1, 'sess1aaaa', 'cancelled');
$log->add(1, 'sess1aaaa', 'idle_close');
$log->add(1, 'sess1aaaa', 'bogus_kind');
$log->add(2, 'sess2bbbb', 'session_start', 88);
check('unknown kinds are ignored; the rest are stored', (int) $db->query('SELECT COUNT(*) FROM voice_events')->fetchColumn() === 8);
check('ownership: the owner sees the session, someone else does not', $log->ownsSession(1, 'sess1aaaa') && !$log->ownsSession(2, 'sess1aaaa') && !$log->ownsSession(1, 'nope'));
check('the session\'s conversation is found', $log->sessionConversation(1, 'sess1aaaa') === 77 && $log->sessionConversation(2, 'sess1aaaa') === null);
check('events of a kind are counted', $log->countKind('sess1aaaa', 'tool_error') === 1);
$s = $log->recentSessions(1, 5);
check('a session summary: tools, errors, interruptions, cancellations, first audio, how it ended', count($s) === 1 && $s[0]['tool_calls'] === 2 && $s[0]['errors'] === 1
    && $s[0]['interruptions'] === 1 && $s[0]['cancelled_calls'] === 1 && $s[0]['first_audio_ms'] === 640 && $s[0]['ended_by'] === 'idle_close', json_encode($s));
check('the notable calls carry latency and the error', $s[0]['notable'][0][1] === 'update_calendar_event' && $s[0]['notable'][0][2] === 950 && $s[0]['notable'][1][0] === 'ERROR' && $s[0]['notable'][1][3] === 'No time given.');
// usage: a session lasts from first to last event
$db->exec("DELETE FROM voice_events");
$now = new DateTimeImmutable('2026-10-19 12:00:00 UTC');
$db->exec("INSERT INTO voice_events (user_id, session_id, kind, created_at) VALUES (1, 'a', 'session_start', '2026-10-19 10:00:00'), (1, 'a', 'idle_close', '2026-10-19 10:04:00'),
    (1, 'b', 'session_start', '2026-10-19 11:00:00'), (1, 'b', 'tool_call', '2026-10-19 11:01:30'),
    (1, 'old', 'session_start', '2026-10-18 10:00:00'), (1, 'old', 'idle_close', '2026-10-18 10:30:00'),
    (2, 'c', 'session_start', '2026-10-19 10:00:00'), (2, 'c', 'idle_close', '2026-10-19 10:09:00')");
check('today\'s seconds: every session of today from first to last event (a closed tab still counts), nothing from yesterday or others', $log->usedSecondsToday(1, $now) === 240 + 90, (string) $log->usedSecondsToday(1, $now));
$db->exec("DELETE FROM voice_events");
$db->exec("INSERT INTO voice_events (user_id, session_id, kind, created_at) VALUES (1, 'x', 'session_start', '2026-10-18 21:30:00'), (1, 'x', 'idle_close', '2026-10-18 21:40:00'),
    (1, 'y', 'session_start', '2026-10-18 22:30:00'), (1, 'y', 'idle_close', '2026-10-18 22:35:00')");
check('the day starts at midnight Copenhagen (22:00 UTC in summer), not at UTC midnight', $log->usedSecondsToday(1, new DateTimeImmutable('2026-10-18 23:30:00 UTC')) === 300,
    (string) $log->usedSecondsToday(1, new DateTimeImmutable('2026-10-18 23:30:00 UTC')));

echo "6) the auto-report and the developer tool\n";
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, role TEXT)");
$db->exec("INSERT INTO users (id, name, role) VALUES (1, 'Dev', 'admin'), (2, 'Alex', 'user')");
$db->exec("CREATE TABLE feedback_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, conversation_id INT, message_id INT, note TEXT, snapshot TEXT, status TEXT DEFAULT 'new', created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$reports = new FeedbackReports($db);
$users   = new Users($db);
$id = VoiceAlert::report($reports, null, $users, 1, 77, ['name' => 'update_calendar_event', 'args' => '{"event_id":"e1"}', 'error' => 'Event not found'], [['role' => 'user', 'content' => 'Move it to Thursday'], ['role' => 'assistant', 'content' => 'Moving it…']]);
$rep = $reports->find($id);
$snap = json_decode($rep['snapshot'], true);
check('a failed call becomes a feedback report with the call and the transcript', $rep['note'] === 'Voice: update_calendar_event failed — Event not found' && $snap['source'] === 'voice'
    && $snap['reported']['diagnostics']['calls'][0]['error'] === 'Event not found' && count($snap['context']) === 2 && (int) $rep['conversation_id'] === 77);
check('it shows up in the open reports', count($reports->recent('open')) === 1);
$tool = new GetVoiceLog($log, $users);
check('only the developer reads the voice log', isset($tool->execute([], 2)['error']) && isset($tool->execute([], 1)['sessions']));

echo "6) spoken confirmation before a delete\n";
$ran = 0;
$reg2 = new ToolRegistry();
$reg2->register(fakeTool('delete_calendar_event', function ($a, $u) use (&$ran) { $ran++; return ['deleted' => true]; }));
$gate = new ConfirmGate($log);
$r2 = new VoiceToolRunner($reg2, $gw, $gate);
$d = ['event_id' => 'e9', 'calendar_id' => 'c1'];
$a = $r2->run('delete_calendar_event', $d, 1, 'sess1aaaa', 3, 77);
check('the first call only asks: not run, told to confirm', $ran === 0 && ($a['result']['needs_confirmation'] ?? false) === true && $a['ok'] === true);
$b = $r2->run('delete_calendar_event', $d, 1, 'sess1aaaa', 3, 77);
check('the same call in the same turn is still held (the model cannot answer for the user)', $ran === 0 && isset($b['result']['needs_confirmation']));
$c = $r2->run('delete_calendar_event', ['event_id' => 'other'], 1, 'sess1aaaa', 4, 77);
check('a different call in a later turn is a new question, not a yes', $ran === 0 && isset($c['result']['needs_confirmation']));
$e = $r2->run('delete_calendar_event', ['calendar_id' => 'c1', 'event_id' => 'e9'], 1, 'sess1aaaa', 4, 77);
check('the same call in a later turn runs (argument order does not matter)', $ran === 1 && ($e['result']['deleted'] ?? false) === true);
$f = $r2->run('delete_calendar_event', $d, 1, 'sess1aaaa', 5, 77);
check('a yes is used once: the next identical call asks again', $ran === 1 && isset($f['result']['needs_confirmation']));
$g = $r2->run('delete_calendar_event', $d, 1, 'sess-other', 9, 77);
check('another session\'s ask does not count', $ran === 1 && isset($g['result']['needs_confirmation']));
$h = (new VoiceToolRunner($reg2, $gw))->run('delete_calendar_event', $d, 1, 'sess1aaaa', 9, 77);
check('without a gate a delete is refused', $ran === 1 && $h['ok'] === false);
check('known tools get a sentence: event with a readable time, no ids', VoiceToolRunner::describe('insert_calendar_event', ['summary' => 'potato', 'start' => '2026-10-19T16:00:00+02:00', 'calendar_id' => 'c1'], true) === 'Created event “potato” · Mon 19 Oct 16:00');
check('a failed call says so; the gateway shows the request', VoiceToolRunner::describe('set_reminder', ['text' => 'milk'], false) === 'Set reminder “milk” (failed)'
    && str_starts_with(VoiceToolRunner::describe(VoiceTools::GATEWAY, ['request' => 'Show my receipts'], true), 'Asked the full assistant · “Show my receipts”'));
$hue = static fn (string $id): ?string => $id === 'aaaa1111-2222' ? 'Hallway' : null;
check('home: a named light is turned off in words, not as a raw request', VoiceToolRunner::describe('home_device_call', ['device' => 'hue', 'method' => 'PUT', 'path' => '/clip/v2/resource/light/aaaa1111-2222', 'body' => ['on' => ['on' => false]]], true, $hue) === 'Turned off Hallway');
check('home: unknown id, dimming, scenes, lookups', VoiceToolRunner::describe('home_device_call', ['device' => 'hue', 'method' => 'PUT', 'path' => '/clip/v2/resource/light/zzzz9999-0000', 'body' => ['on' => ['on' => true]]], true, $hue) === 'Turned on a light'
    && VoiceToolRunner::describe('home_device_call', ['device' => 'hue', 'method' => 'PUT', 'path' => '/clip/v2/resource/light/aaaa1111-2222', 'body' => ['dimming' => ['brightness' => 40]]], true, $hue) === 'Dimmed Hallway to 40%'
    && VoiceToolRunner::describe('home_device_call', ['device' => 'hue', 'method' => 'GET', 'path' => '/clip/v2/resource/light'], true) === 'Looked up your lights');
check('an unknown tool: humanised name plus word-like args only', VoiceToolRunner::describe('log_workout', ['exercise' => 'Bench press', 'workout_id' => 4, 'sets' => 3], true) === 'Added workout · Bench press'
    && VoiceToolRunner::describe('get_trips', [], true) === 'Checked trips');
check('a held delete has no summary (nothing was done)', $a['summary'] === null && $e['summary'] === 'Deleted an event');
foreach (['schedule_task', 'list_scheduled_tasks', 'notify_me', 'get_workout_progress', 'log_period', 'get_trips', 'list_places', 'get_wishlist', 'get_vinyls', 'uncheck_item'] as $n) {
    $okAll = ($okAll ?? true) && VoiceTools::isAllowed($n) && !VoiceTools::needsConfirm($n) && !VoiceTools::isSlow($n);
}
check('the new groups are direct tools (tasks, health, trips/places, lists)', $okAll);
check('maps/Discogs lookups are slow; deletes of those groups need a yes', VoiceTools::isSlow('log_trip') && VoiceTools::isSlow('suggest_places') && VoiceTools::isSlow('add_vinyl')
    && VoiceTools::needsConfirm('delete_trip') && VoiceTools::needsConfirm('cancel_scheduled_task') && VoiceTools::needsConfirm('remove_period') && VoiceTools::needsConfirm('delete_place'));
check('no tool is both slow and confirm; none listed twice', array_intersect(VoiceTools::SLOW, VoiceTools::CONFIRM) === [] && count(VoiceTools::allowed()) === count(array_unique(VoiceTools::allowed())));
$setupEn = VoiceSession::setup($registry, '', '', 'auto', [], [], '', null, 'en');
$setupDa = VoiceSession::setup($registry, '', '', 'auto', [], [], '', null, 'da');
$setupAuto = VoiceSession::setup($registry, '', '', 'auto');
check('language: auto = English or Danish only, no language code; en/da pin the accent', !isset($setupAuto['generationConfig']['speechConfig']['languageCode'])
    && str_contains($setupAuto['systemInstruction']['parts'][0]['text'], 'ONLY English or Danish')
    && $setupEn['generationConfig']['speechConfig']['languageCode'] === 'en-US' && $setupDa['generationConfig']['speechConfig']['languageCode'] === 'da-DK'
    && str_contains($setupDa['systemInstruction']['parts'][0]['text'], 'always speak Danish') && VoiceSession::language('fr') === 'auto');
check('language choice: saved wins; unset follows the browser (Danish → da, else en)', VoiceSession::resolveLanguage('da', 'en-US') === 'da' && VoiceSession::resolveLanguage('en', 'da-DK') === 'en'
    && VoiceSession::resolveLanguage('auto', 'da-DK') === 'da' && VoiceSession::resolveLanguage('auto', 'en-GB') === 'en' && VoiceSession::resolveLanguage(null, '') === 'en');
check('the voice name stays set, and the prompt asks for one consistent voice', $setupAuto['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Puck'
    && str_contains($setupAuto['systemInstruction']['parts'][0]['text'], 'same voice, accent and tone'));
check('end_conversation is declared for the model but is not a server tool', in_array(VoiceTools::END, array_column($decl, 'name'), true) && !VoiceTools::isAllowed(VoiceTools::END)
    && VoiceToolRunner::describe(VoiceTools::END, [], true) !== '');
check('stop words: parsed lower-case, unique, capped', VoiceSession::stopWords("Farvel, STOP tale,\nfarvel ; goodbye,, ") === ['farvel', 'stop tale', 'goodbye'] && count(VoiceSession::stopWords(implode(',', range(1, 40)))) === 20);
$pr = VoiceSession::prompt([], [], '', null, 'auto', ['farvel', 'stop tale']);
check('the prompt names the stop words and the end tool, and says never to end it alone', str_contains($pr, '"farvel", "stop tale"') && str_contains($pr, 'end_conversation') && str_contains($pr, 'never end it on your own'));
check('the prompt tells the model how deleting works', str_contains(VoiceSession::prompt([], [], ''), 'ONE short question'));

echo "9) the kiosk's voice: the house only\n";
$kreg = new ToolRegistry();
foreach (array_merge(KioskVoice::TOOLS, ['get_emails', 'get_calendar_events', 'get_memories']) as $n) {
    $kreg->register(fakeTool($n, fn ($a, $u) => ['ok' => true, 'name' => $n]));
}
$kdecl  = VoiceTools::declarations($kreg, 'auto', KioskVoice::TOOLS);
$knames = array_column($kdecl, 'name');
sort($knames);
$want = array_merge(KioskVoice::TOOLS, [VoiceTools::END]);
sort($want);
check('declarations: exactly the kiosk tools + end_conversation; no gateway, no mail, no calendar', $knames === $want, json_encode($knames));
check('the normal declarations still carry the gateway', in_array(VoiceTools::GATEWAY, array_column(VoiceTools::declarations($registry), 'name'), true));
$kgate = static fn (string $r): array => ['reply' => 'x', 'card' => null];
$krun  = new VoiceToolRunner($kreg, $kgate, null, null, KioskVoice::TOOLS);
check('kiosk runner: a home tool runs', $krun->run('home_device_call', [], 1)['ok'] === true);
$mail = $krun->run('get_emails', [], 1);
check('kiosk runner: mail is refused (not on the list, even though it is registered)', $mail['ok'] === false && str_contains((string) $mail['error'], 'not allowed'));
check('kiosk runner: calendar is refused even though the normal voice allows it', VoiceTools::isAllowed('get_calendar_events') && $krun->run('get_calendar_events', [], 1)['ok'] === false);
check('kiosk runner: the gateway to the full assistant is refused', $krun->run(VoiceTools::GATEWAY, ['request' => 'my balance'], 1)['ok'] === false);
check('kiosk tool list: no tool needs a spoken confirmation, and slow ones are all on the list', array_filter(KioskVoice::TOOLS, static fn ($t) => VoiceTools::needsConfirm($t)) === []
    && array_diff(KioskVoice::SLOW, KioskVoice::TOOLS) === []);
$ksetup = VoiceSession::setup($kreg, '', '', 'auto', [['content' => 'secret memory']], [['instruction' => 'secret rule']], 'secret agenda', null, 'da', ['farvel'], 'secret routine', KioskVoice::TOOLS, KioskVoice::prompt('da', ['farvel']));
$ktext = $ksetup['systemInstruction']['parts'][0]['text'];
check('kiosk prompt: house-only, Danish, stop words; no gateway mention', str_contains($ktext, 'house assistant') && str_contains($ktext, 'always speak Danish') && str_contains($ktext, '"farvel"') && !str_contains($ktext, VoiceTools::GATEWAY)
    && !str_contains($ktext, 'secret'));   // memories, standing instructions, agenda and routines passed in are ignored
check('kiosk setup: only the kiosk tools are declared to the model', array_column($ksetup['tools'][0]['functionDeclarations'], 'name') === array_column($kdecl, 'name'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
