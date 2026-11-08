<?php

declare(strict_types=1);

namespace App\Recruiter;

/**
 * The owner's "Recruiter" card: the links with their state, the messages recruiters left, and the facts (counts by visibility + each entry with its visibility, to change
 * in a dropdown). Built from the same classes the tools use; the endpoint (api/recruiter.php) is owner-only.
 */
final class RecruiterCard
{
    /** @return array<string, mixed> */
    public static function build(int $userId, RecruiterLinks $links, AboutEntries $entries, string $host): array
    {
        $linkRows = [];
        foreach ($links->list($userId) as $l) {
            $linkRows[] = [
                'id' => (int) $l['id'], 'label' => (string) $l['label'], 'company' => $l['company'], 'role' => $l['role_title'], 'state' => RecruiterLinks::state($l),
                'url' => 'https://' . $host . '/meet/' . $l['token'], 'opens' => (int) $l['open_count'], 'last_opened_at' => $l['last_opened_at'],
                'messages' => (int) $l['messages_used'], 'message_cap' => (int) $l['message_cap'], 'tokens' => (int) $l['tokens_used'], 'token_cap' => (int) $l['token_cap'], 'expires_at' => $l['expires_at'],
            ];
        }
        $leads = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'contact' => $r['contact'], 'message' => $r['message'], 'at' => $r['created_at'], 'link' => $r['link_label'],
            'unread' => $r['read_at'] === null, 'delivered' => (int) ($r['push_ok'] ?? 0) === 1 || (int) ($r['email_ok'] ?? 0) === 1,
        ], $links->leads($userId));
        $all    = $entries->all($userId);
        $counts = ['public' => 0, 'summary' => 0, 'private' => 0];
        foreach ($all as $e) {
            $counts[$e['visibility']] = ($counts[$e['visibility']] ?? 0) + 1;
        }

        return [
            'kind'    => 'recruiter',
            'title'   => 'Recruiter',
            'links'   => $linkRows,
            'leads'   => $leads,
            // What recruiters asked that the facts could not answer (newest first) — the list of what to add next.
            'gaps'    => array_map(static fn (array $g): array => ['id' => (int) $g['id'], 'question' => $g['question'], 'topic' => $g['topic'], 'link' => $g['link_label'], 'at' => $g['created_at']], $links->gaps($userId, 30)),
            'facts'   => $counts + ['total' => count($all)],
            'entries' => array_map(static fn (array $e): array => [
                'id' => (int) $e['id'], 'category' => $e['category'], 'title' => $e['title'], 'visibility' => $e['visibility'], 'has_summary' => trim((string) ($e['summary'] ?? '')) !== '',
            ], $all),
        ];
    }
}
