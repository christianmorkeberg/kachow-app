<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Reminders;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: list the user's upcoming (pending) reminders.
 */
final class ListReminders implements Tool
{
    private const LOCAL_TZ = 'Europe/Copenhagen';

    public function __construct(private Reminders $reminders)
    {
    }

    public function name(): string
    {
        return 'list_reminders';
    }

    public function description(): string
    {
        return 'Lists the user\'s upcoming reminders (the ones not yet fired). Use for "what reminders '
            . 'do I have?", "my reminders", Danish "hvilke påmindelser har jeg?". Give the id when the '
            . 'user might want to cancel one.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $tz  = new DateTimeZone(self::LOCAL_TZ);
        $out = [];
        foreach ($this->reminders->upcoming($userId) as $r) {
            $when = (new DateTimeImmutable($r['remind_at'], new DateTimeZone('UTC')))->setTimezone($tz);
            $out[] = ['id' => $r['id'], 'text' => $r['text'], 'when' => $when->format('D j M, H:i')];
        }

        return ['count' => count($out), 'reminders' => $out];
    }
}
