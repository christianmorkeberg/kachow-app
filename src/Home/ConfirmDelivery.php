<?php

declare(strict_types=1);

namespace App\Home;

use App\Data\Conversations;
use App\Notify\NotificationTypes;
use App\Notify\Notifier;

/**
 * How a settled radiator batch reaches the user: the text is stored as an assistant message in the conversation the request came from, and pushed
 * (NotificationTypes::FOLLOW_UP, tapping opens that chat). Used by the watcher after the reply and by the gateway's call (api/shelly-hook.php).
 */
final class ConfirmDelivery
{
    public function __construct(private int $userId, private ?Notifier $notifier = null, private ?Conversations $conversations = null)
    {
    }

    public function __invoke(string $text, ?int $conversationId): void
    {
        if ($conversationId !== null && $conversationId > 0) {
            try {
                ($this->conversations ?? new Conversations())->addMessage($conversationId, 'assistant', $text);
            } catch (\Throwable $e) {
                error_log('ConfirmDelivery message: ' . $e->getMessage());
            }
        }
        try {
            ($this->notifier ?? Notifier::fromEnv())->notify($this->userId, NotificationTypes::FOLLOW_UP, 'Kachow', $text, $conversationId ? '/?conv=' . $conversationId : null);
        } catch (\Throwable $e) {
            error_log('ConfirmDelivery push: ' . $e->getMessage());
        }
    }
}
