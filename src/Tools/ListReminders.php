<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Reminders;

/**
 * Tool: show the user's reminders (open ones awaiting approval, and upcoming ones) as a card.
 */
final class ListReminders implements Tool
{
    public function __construct(private Reminders $reminders)
    {
    }

    public function name(): string
    {
        return 'list_reminders';
    }

    public function description(): string
    {
        return 'Shows the user\'s reminders: the OPEN ones (fired and still waiting for the user to approve them) and the '
            . 'upcoming ones (timed, or place ones "next time at <place>"). Use for "what reminders do I have?", '
            . '"my reminders", "what have I not done yet?", Danish "hvilke påmindelser har jeg?". Shows a card the user can '
            . 'tick; give the id when the user might want to approve, nudge or cancel one.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $card = $this->reminders->card($userId);

        return [
            'open_count' => count($card['open']),
            // Fired and not yet approved: the user ticks these on the card, or you resolve them with resolve_reminder.
            'open'       => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'text' => $r['text'], 'fired' => $r['fired'],
                'nudge_at' => $r['nudge'], 'place' => $r['place'],
            ], $card['open']),
            'upcoming'   => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'text' => $r['text'],
                'when' => $r['when'] ?? ($r['place'] !== null ? 'next time at ' . $r['place'] : null),
            ], $card['upcoming']),
            '_render'    => $card,
        ];
    }
}
