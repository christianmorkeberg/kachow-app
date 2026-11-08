<?php

declare(strict_types=1);

namespace App\Recruiter;

use App\Assistant\GeminiClient;
use Throwable;

/**
 * One turn of the recruiter chat. A purpose-built loop, NOT the main assistant: it has exactly three tools (look up facts, look up Kachow, leave a message), no access to
 * any user data, no memories, no routines — nothing a prompt injection could reach. The facts come only through AboutEntries (visibility filtered in SQL).
 */
final class RecruiterChat
{
    private const MAX_ROUNDS     = 4;
    private const MAX_USER_CHARS = 1500;
    private const HISTORY        = 24;
    /** A recruiter may leave at most this many messages per link per day (the assistant cannot be used to spam the owner). */
    private const LEADS_PER_DAY  = 3;

    public function __construct(
        private GeminiClient $gemini,
        private AboutEntries $entries,
        private RecruiterLinks $links,
        private LeadDelivery $delivery,
        private string $ownerName = 'the owner',
    ) {
    }

    /**
     * @param array<string, mixed> $link
     * @return array{reply:string, tokens:int, tools:list<string>, error:?string}
     */
    public function reply(array $link, string $userText, ?string $ipHash = null): array
    {
        $userText = trim(mb_substr($userText, 0, self::MAX_USER_CHARS));
        if ($userText === '') {
            return ['reply' => '', 'tokens' => 0, 'tools' => [], 'error' => 'empty'];
        }
        $owner = (int) $link['user_id'];
        $linkId = (int) $link['id'];

        $contents = [];
        foreach ($this->links->messages($linkId, self::HISTORY) as $m) {
            $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userText]]];

        $system = RecruiterPrompt::build($link, $this->ownerName, $this->entries->index($owner), $this->entries->search($owner, $userText, 4, null, $this->nameWords()));
        $tools  = RecruiterPrompt::tools($this->ownerName);
        $tokens = 0;
        $used   = [];
        $reply  = '';

        try {
            for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
                $response = $this->gemini->generate($contents, $tools, $system, null, null, 30000, $round === self::MAX_ROUNDS - 1 ? 'NONE' : null);
                $tokens  += (int) ($response['usageMetadata']['totalTokenCount'] ?? 0);
                $calls    = GeminiClient::extractFunctionCalls($response);
                if ($calls === []) {
                    $reply = trim(GeminiClient::extractText($response));
                    break;
                }
                $contents[] = GeminiClient::firstCandidateContent($response);
                $parts = [];
                foreach ($calls as $c) {
                    $used[] = $c['name'];
                    $result = $this->run($link, (string) $c['name'], is_array($c['args']) ? $c['args'] : [], $ipHash);
                    $fr = ['name' => $c['name'], 'response' => $result];
                    if (isset($c['id'])) {
                        $fr['id'] = $c['id'];
                    }
                    $parts[] = ['functionResponse' => $fr];
                }
                $contents[] = ['role' => 'user', 'parts' => $parts];
            }
        } catch (Throwable $e) {
            error_log('recruiter chat: ' . $e->getMessage());

            return ['reply' => '', 'tokens' => $tokens, 'tools' => $used, 'error' => 'model'];   // nothing is stored or counted: the recruiter can simply try again
        }

        if ($reply === '') {
            $reply = 'Sorry — I could not put an answer together. Could you ask again, or leave ' . $this->ownerName . ' a message?';
        }
        // Claiming that a message was passed on without the tool having saved one would be a lie to the recruiter: correct it.
        if (self::claimsDelivery($reply) && !in_array('leave_message', $used, true)) {
            $reply .= "\n\n(To be clear: I have not sent anything to " . $this->ownerName . " yet. Tell me your name, how to reach you and your message, and I will pass it on.)";
        }

        $this->links->addMessage($linkId, 'user', $userText, 0, $ipHash);
        $this->links->addMessage($linkId, 'assistant', $reply, $tokens);
        $this->links->addUsage($linkId, $tokens, 1);

        return ['reply' => $reply, 'tokens' => $tokens, 'tools' => $used, 'error' => null];
    }

    /** @return list<string> the words of the owner's name (left out of fact searches) */
    private function nameWords(): array
    {
        preg_match_all('/[\p{L}]{3,}/u', $this->ownerName, $m);

        return $m[0];
    }

    /** Whether a reply says a message has been passed on / sent (en + da). */
    public static function claimsDelivery(string $reply): bool
    {
        return (bool) preg_match('/\b(i\'?ve|i\s+have|i\s+just|i\s+will\s+now|has\s+been|have\s+been|is\s+now)\s+(now\s+)?(passed|sent|forwarded|delivered|saved|noted)\b[^.!?\n]{0,60}\b(message|note|it|that)\b'
            . '|\b(message|note)\b[^.!?\n]{0,40}\b(has|have|is|was)\s+(now\s+|been\s+)*(passed|sent|forwarded|delivered|saved)\b'
            . '|\b(jeg\s+har|er\s+nu|blev)\s+(nu\s+|lige\s+)?(videregivet|sendt|givet\s+besked|gemt|noteret|leveret)\b[^.!?\n]{0,60}\b(besked|det|den)\b'
            . '|\bbesked\b[^.!?\n]{0,40}\b(er|blev)\s+(nu\s+)?(videregivet|sendt|leveret|gemt)\b/iu', $reply);
    }

    /**
     * Runs one of the three tools.
     *
     * @param array<string, mixed> $link
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function run(array $link, string $name, array $args, ?string $ipHash): array
    {
        $owner = (int) $link['user_id'];
        if ($name === 'get_about_owner' || $name === 'explain_kachow') {
            $topic = trim((string) ($args['topic'] ?? ''));
            $all   = $name === 'explain_kachow'
                ? $this->entries->search($owner, $topic, 5, 'kachow', $this->nameWords())
                : array_values(array_filter($this->entries->search($owner, $topic, 8, null, $this->nameWords()), static fn (array $e): bool => $e['category'] !== 'kachow'));
            $hits = array_slice($all, 0, 5);
            if ($hits === []) {
                return ['found' => false, 'note' => 'Nothing in the facts covers that. Say you do not know it, and offer to pass a message to ' . $this->ownerName . '.'];
            }

            return [
                'found'   => true,
                'entries' => array_map(static fn (array $e): array => ['title' => $e['title'], 'text' => $e['text'], 'level' => $e['level']], $hits),
                'note'    => 'Use only this. Entries with level "summary" may be said only as given, with no added detail.',
            ];
        }
        if ($name === 'leave_message') {
            $n = trim((string) ($args['name'] ?? ''));
            $c = trim((string) ($args['contact'] ?? ''));
            $m = trim((string) ($args['message'] ?? ''));
            if ($n === '' || mb_strlen($c) < 5 || $m === '') {
                return ['saved' => false, 'error' => 'Ask the recruiter for their name, an email or phone number, and the message first.'];
            }
            if ($this->links->leadsToday((int) $link['id']) >= self::LEADS_PER_DAY) {
                return ['saved' => false, 'error' => 'Several messages have already been left today. Say ' . $this->ownerName . ' has them and will be in touch.'];
            }
            $leadId = $this->links->addLead((int) $link['id'], $n, $c, $m);   // saved FIRST
            $sent   = $this->delivery->deliver($link, $leadId, $n, $c, $m);

            return ['saved' => true, 'note' => 'Saved, and ' . $this->ownerName . ' has been notified' . ($sent['push'] || $sent['email'] ? '.' : ' — the notification will be retried automatically.') . ' Tell the recruiter it is passed on.'];
        }

        return ['error' => 'Unknown tool.'];
    }
}
