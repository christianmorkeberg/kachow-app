<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Users;
use App\Recruiter\AboutEntries;

/** Tool (owner/admin only): changes one fact (or adds one) for the recruiter assistant — and above all its visibility: public, summary (only the short summary is used) or private. */
final class UpdateAboutEntry implements Tool
{
    public function __construct(private Users $users, private AboutEntries $entries)
    {
    }

    public function name(): string
    {
        return 'update_about_entry';
    }

    public function description(): string
    {
        return 'Changes an entry the recruiter assistant uses (id from list_about_entries): set its visibility to "public", "summary" (the assistant may only say the short summary — give one) or "private", '
            . 'or edit its title, body, summary. Without an id it ADDS a new entry (needs category, title, body, visibility). action "delete" removes one. '
            . 'Use for "make the freelance entry private", "add a fact about …".';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'id'         => ['type' => 'integer', 'description' => 'The entry to change (omit to add a new one).'],
            'visibility' => ['type' => 'string', 'description' => '"public", "summary" or "private".'],
            'title'      => ['type' => 'string', 'description' => 'Title.'],
            'category'   => ['type' => 'string', 'description' => 'Category, e.g. work, research, education, skills, style, goals, kachow.'],
            'body'       => ['type' => 'string', 'description' => 'The full text (what the assistant may say when the entry is public).'],
            'summary'    => ['type' => 'string', 'description' => 'The short text used when visibility is "summary" (empty string removes it).'],
            'action'     => ['type' => 'string', 'description' => '"delete" to remove the entry.'],
        ]];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Only the owner can change these.'];
        }
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            $title = trim((string) ($arguments['title'] ?? ''));
            $body  = trim((string) ($arguments['body'] ?? ''));
            if ($title === '' || $body === '' || trim((string) ($arguments['category'] ?? '')) === '') {
                return ['error' => 'To add an entry give category, title and body (and a visibility — it defaults to private).'];
            }
            $newId = $this->entries->add($userId, (string) $arguments['category'], $title, $body, isset($arguments['summary']) && trim((string) $arguments['summary']) !== '' ? (string) $arguments['summary'] : null, (string) ($arguments['visibility'] ?? 'private'));

            return ['added' => true, 'id' => $newId];
        }
        $exists = array_filter($this->entries->all($userId), static fn (array $e): bool => (int) $e['id'] === $id);
        if ($exists === []) {
            return ['error' => 'No such entry.'];
        }
        if (strtolower(trim((string) ($arguments['action'] ?? ''))) === 'delete') {
            $this->entries->remove($userId, $id);

            return ['deleted' => true, 'id' => $id];
        }
        $this->entries->update($userId, $id, array_intersect_key($arguments, array_flip(['title', 'category', 'body', 'summary', 'visibility'])));
        $now = array_values(array_filter($this->entries->all($userId), static fn (array $e): bool => (int) $e['id'] === $id))[0];
        $warn = $now['visibility'] === 'summary' && trim((string) ($now['summary'] ?? '')) === '' ? 'This entry is "summary" but has no summary text, so the assistant will say nothing about it — give it one.' : null;

        return ['updated' => true, 'id' => $id, 'title' => $now['title'], 'visibility' => $now['visibility'], 'warning' => $warn];
    }
}
