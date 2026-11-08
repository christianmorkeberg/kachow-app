<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Users;
use App\Recruiter\AboutEntries;

/** Tool (owner/admin only): the facts the recruiter assistant draws on — id, category, title, visibility and a preview — so the owner can review what it may say. */
final class ListAboutEntries implements Tool
{
    public function __construct(private Users $users, private AboutEntries $entries)
    {
    }

    public function name(): string
    {
        return 'list_about_entries';
    }

    public function description(): string
    {
        return 'Lists the facts the recruiter assistant can use about the owner (id, category, title, visibility public / summary / private, and a short preview). Pass id to read one entry in full. '
            . 'Use for "what does the recruiter assistant know about me", "show me the entries", "what is public".';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'id'       => ['type' => 'integer', 'description' => 'Read one entry in full.'],
            'category' => ['type' => 'string', 'description' => 'Only this category (e.g. work, research, education, skills, kachow).'],
        ]];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Only the owner can see these.'];
        }
        $id  = (int) ($arguments['id'] ?? 0);
        $cat = isset($arguments['category']) ? trim((string) $arguments['category']) : '';
        $out = [];
        foreach ($this->entries->all($userId) as $e) {
            if ($id > 0 && (int) $e['id'] !== $id) {
                continue;
            }
            if ($cat !== '' && $e['category'] !== $cat) {
                continue;
            }
            $row = ['id' => (int) $e['id'], 'category' => $e['category'], 'title' => $e['title'], 'visibility' => $e['visibility']];
            if ($id > 0) {
                $row['body'] = $e['body'];
                $row['summary'] = $e['summary'];
            } else {
                $row['preview'] = mb_substr((string) $e['body'], 0, 90) . (mb_strlen((string) $e['body']) > 90 ? '…' : '');
                $row['has_summary'] = trim((string) ($e['summary'] ?? '')) !== '';
            }
            $out[] = $row;
        }

        return ['count' => count($out), 'entries' => $out, 'note' => 'public = the assistant may say the whole text; summary = only the short summary; private = never used.'];
    }
}
