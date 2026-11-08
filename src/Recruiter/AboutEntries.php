<?php

declare(strict_types=1);

namespace App\Recruiter;

use App\Database;
use PDO;

/**
 * The catalogue of facts about the owner that the recruiter assistant may use. Every entry has a visibility:
 *   public  — the assistant may say the whole body;
 *   summary — it may only say the short `summary` (the body never leaves the table);
 *   private — never read for the assistant at all.
 * The filter is in the SQL, so a private entry cannot reach a prompt or a tool result by a coding slip further on.
 */
final class AboutEntries
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** @return int the new id */
    public function add(int $userId, string $category, string $title, string $body, ?string $summary, string $visibility, string $lang = 'en', int $sort = 0): int
    {
        $this->db->prepare(
            'INSERT INTO about_entries (user_id, category, title, body, summary, visibility, lang, sort) VALUES (:u, :c, :t, :b, :s, :v, :l, :o)'
        )->execute([
            ':u' => $userId, ':c' => mb_substr($category, 0, 40), ':t' => mb_substr($title, 0, 160), ':b' => $body,
            ':s' => $summary, ':v' => self::visibility($visibility), ':l' => substr($lang, 0, 2), ':o' => $sort,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Replaces all of a user's entries with these (the import). @param list<array<string, mixed>> $entries @return int how many */
    public function replaceAll(int $userId, array $entries): int
    {
        $this->db->prepare('DELETE FROM about_entries WHERE user_id = :u')->execute([':u' => $userId]);
        $n = 0;
        foreach ($entries as $i => $e) {
            $this->add(
                $userId, (string) ($e['category'] ?? 'general'), (string) ($e['title'] ?? ''), (string) ($e['body'] ?? ''),
                isset($e['summary']) && $e['summary'] !== '' ? (string) $e['summary'] : null, (string) ($e['visibility'] ?? 'private'),
                (string) ($e['lang'] ?? 'en'), (int) ($e['sort'] ?? $i)
            );
            $n++;
        }

        return $n;
    }

    /** Largest import accepted: entries, and characters per entry body. */
    public const MAX_ENTRIES = 300;
    public const MAX_BODY    = 8000;

    /**
     * Checks a JSON text from the import (a paste or an uploaded file) and turns it into clean entries. Nothing is written. Visibility defaults to PRIVATE when missing or unknown.
     *
     * @return array{entries: list<array<string, mixed>>, errors: list<string>, counts: array{public:int, summary:int, private:int}}
     */
    public static function parseImport(string $json): array
    {
        $out = ['entries' => [], 'errors' => [], 'counts' => ['public' => 0, 'summary' => 0, 'private' => 0]];
        $data = json_decode(trim($json), true);
        if (!is_array($data) || $data === [] || array_keys($data) !== range(0, count($data) - 1)) {
            $out['errors'][] = 'That is not a JSON list of entries (it should start with [ and hold objects with category, title, body, visibility).';

            return $out;
        }
        if (count($data) > self::MAX_ENTRIES) {
            $out['errors'][] = 'Too many entries (' . count($data) . '; the limit is ' . self::MAX_ENTRIES . ').';

            return $out;
        }
        foreach ($data as $i => $e) {
            $n = $i + 1;
            if (!is_array($e)) {
                $out['errors'][] = "Entry {$n} is not an object.";
                continue;
            }
            $title = trim((string) ($e['title'] ?? ''));
            $body  = trim((string) ($e['body'] ?? ''));
            if ($title === '' || $body === '') {
                $out['errors'][] = "Entry {$n} needs a title and a body.";
                continue;
            }
            if (mb_strlen($body) > self::MAX_BODY) {
                $out['errors'][] = "Entry {$n} (\"" . mb_substr($title, 0, 40) . "\") is longer than " . self::MAX_BODY . ' characters — split it in two.';
                continue;
            }
            $vis = self::visibility((string) ($e['visibility'] ?? 'private'));
            $out['counts'][$vis]++;
            $out['entries'][] = [
                'category' => trim((string) ($e['category'] ?? '')) ?: 'general', 'title' => $title, 'body' => $body,
                'summary' => isset($e['summary']) && trim((string) $e['summary']) !== '' ? trim((string) $e['summary']) : null,
                'visibility' => $vis, 'lang' => substr((string) ($e['lang'] ?? 'en'), 0, 2) ?: 'en', 'sort' => (int) ($e['sort'] ?? $i),
            ];
            if ($vis === 'summary' && $out['entries'][count($out['entries']) - 1]['summary'] === null) {
                $out['errors'][] = "Entry {$n} (\"" . mb_substr($title, 0, 40) . "\") is summary-only but has no summary, so the assistant would say nothing about it.";
            }
        }

        return $out;
    }

    /** Loads clean entries: "replace" swaps the user's whole catalogue, "add" appends. @param list<array<string, mixed>> $entries @return int how many were written */
    public function import(int $userId, array $entries, string $mode = 'replace'): int
    {
        if ($mode === 'add') {
            $sort = (int) $this->db->query('SELECT COALESCE(MAX(sort), 0) FROM about_entries WHERE user_id = ' . (int) $userId)->fetchColumn();
            foreach ($entries as $i => $e) {
                $this->add($userId, (string) $e['category'], (string) $e['title'], (string) $e['body'], $e['summary'] ?? null, (string) $e['visibility'], (string) ($e['lang'] ?? 'en'), $sort + 1 + $i);
            }

            return count($entries);
        }

        return $this->replaceAll($userId, $entries);
    }

    /** Every entry in the import format — the backup file. @return list<array<string, mixed>> */
    public function export(int $userId): array
    {
        return array_map(static fn (array $r): array => array_filter([
            'category' => $r['category'], 'title' => $r['title'], 'body' => $r['body'], 'summary' => $r['summary'], 'visibility' => $r['visibility'], 'lang' => $r['lang'],
        ], static fn ($v): bool => $v !== null), $this->all($userId));
    }

    /**
     * What the assistant may know, per entry: public ones with their body, summary-only ones with ONLY the summary. Private entries are not selected.
     *
     * @return list<array{id:int, category:string, title:string, text:string, level:string}>
     */
    public function visible(int $userId, ?string $category = null): array
    {
        $sql    = "SELECT id, category, title, body, summary, visibility FROM about_entries WHERE user_id = :u AND visibility IN ('public','summary')";
        $params = [':u' => $userId];
        if ($category !== null) {
            $sql .= ' AND category = :c';
            $params[':c'] = $category;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY sort, id');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $public = $r['visibility'] === 'public';
            $text   = $public ? (string) $r['body'] : trim((string) ($r['summary'] ?? ''));
            if ($text === '') {
                continue;   // a summary-only entry with no summary says nothing
            }
            $out[] = ['id' => (int) $r['id'], 'category' => (string) $r['category'], 'title' => (string) $r['title'], 'text' => $text, 'level' => $public ? 'public' : 'summary'];
        }

        return $out;
    }

    /** The entries that best match a topic or question, best first (word overlap in title, category and text; title counts most). $ignore = words to leave out (the owner's name). @param list<string> $ignore @return list<array{id:int, category:string, title:string, text:string, level:string}> */
    public function search(int $userId, string $topic, int $limit = 5, ?string $category = null, array $ignore = []): array
    {
        $ignore = array_map('mb_strtolower', $ignore);   // the owner's own name: it is in every entry, so it says nothing about which one fits
        $words  = array_values(array_filter(self::words($topic), static fn (string $w): bool => !in_array($w, $ignore, true)));
        $all   = $this->visible($userId, $category);
        if ($words === []) {
            return array_slice($all, 0, $limit);
        }
        $scored = [];
        foreach ($all as $i => $e) {
            $title = mb_strtolower($e['title'] . ' ' . $e['category']);
            $text  = mb_strtolower($e['text']);
            $score = 0;
            foreach ($words as $w) {
                $score += 3 * substr_count($title, $w) + min(3, substr_count($text, $w));
            }
            if ($score > 0) {
                $scored[] = [$score, $i, $e];
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_map(static fn (array $s): array => $s[2], array_slice($scored, 0, $limit));
    }

    /** Titles only, by category — the table of contents the model sees so it knows what to look up. @return array<string, list<string>> */
    public function index(int $userId): array
    {
        $out = [];
        foreach ($this->visible($userId) as $e) {
            $out[$e['category']][] = $e['title'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> every entry incl. private ones, for the owner's own review */
    public function all(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id, category, title, body, summary, visibility, lang, sort FROM about_entries WHERE user_id = :u ORDER BY sort, id');
        $stmt->execute([':u' => $userId]);

        return $stmt->fetchAll();
    }

    /** Changes the given fields of one entry (title, body, summary, category, visibility). @param array<string, mixed> $fields */
    public function update(int $userId, int $id, array $fields): bool
    {
        $set = [];
        $par = [':id' => $id, ':u' => $userId];
        foreach (['title' => 160, 'category' => 40] as $k => $max) {
            if (isset($fields[$k]) && trim((string) $fields[$k]) !== '') { $set[] = "$k = :$k"; $par[":$k"] = mb_substr(trim((string) $fields[$k]), 0, $max); }
        }
        if (isset($fields['body']) && trim((string) $fields['body']) !== '') { $set[] = 'body = :body'; $par[':body'] = (string) $fields['body']; }
        if (array_key_exists('summary', $fields)) { $set[] = 'summary = :summary'; $par[':summary'] = $fields['summary'] === null || trim((string) $fields['summary']) === '' ? null : (string) $fields['summary']; }
        if (isset($fields['visibility'])) { $set[] = 'visibility = :visibility'; $par[':visibility'] = self::visibility((string) $fields['visibility']); }
        if ($set === []) {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE about_entries SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :u');
        $stmt->execute($par);

        return $stmt->rowCount() > 0;
    }

    public function remove(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM about_entries WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function setVisibility(int $userId, int $id, string $visibility): bool
    {
        $stmt = $this->db->prepare('UPDATE about_entries SET visibility = :v WHERE id = :id AND user_id = :u');
        $stmt->execute([':v' => self::visibility($visibility), ':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    private static function visibility(string $v): string
    {
        return in_array($v, ['public', 'summary', 'private'], true) ? $v : 'private';   // anything unknown is private
    }

    /** @return list<string> lower-case words of 3+ letters, without the commonest filler */
    private static function words(string $text): array
    {
        $stop = ['the', 'and', 'for', 'with', 'about', 'what', 'who', 'how', 'does', 'did', 'his', 'her', 'tell', 'det', 'og', 'til', 'med', 'hvad', 'hvem', 'hvordan', 'har', 'fortæl', 'om', 'ved'];
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $m);
        // "meters" must find "meter": a plural s is cut off words of 5+ letters.
        $words = array_map(static fn (string $w): string => mb_strlen($w) >= 5 && str_ends_with($w, 's') ? mb_substr($w, 0, -1) : $w, $m[0]);

        return array_values(array_unique(array_filter($words, static fn (string $w): bool => !in_array($w, $stop, true))));
    }
}
