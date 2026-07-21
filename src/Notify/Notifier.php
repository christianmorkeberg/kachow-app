<?php

declare(strict_types=1);

namespace App\Notify;

use App\Data\NotificationPrefs;
use App\Data\PushSubscriptions;

/**
 * High-level push: respects the user's per-type preference, fans a notification
 * out to all their devices, and prunes dead subscriptions. Everything that wants
 * to notify a user goes through here, so preference/pruning logic lives in one
 * place.
 */
final class Notifier
{
    public function __construct(
        private PushSubscriptions $subscriptions,
        private NotificationPrefs $prefs,
        private Sender $sender,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(new PushSubscriptions(), new NotificationPrefs(), WebPush::fromEnv());
    }

    /**
     * Sends a notification of $type to a user, if they have it enabled and have at
     * least one device. Returns how many devices it actually reached.
     */
    public function notify(int $userId, string $type, string $title, string $body, ?string $url = null): int
    {
        if (!NotificationTypes::exists($type) || !$this->prefs->isEnabled($userId, $type)) {
            return 0;
        }

        return $this->deliver($userId, $type, $title, $body, $url);
    }

    /** Sends a test notification to all the user's devices, ignoring preferences. */
    public function sendTest(int $userId): int
    {
        return $this->deliver($userId, 'test', 'Kachow', 'Notifications are working ✅', '/');
    }

    /** Fans a payload out to every device, pruning any that have expired. */
    private function deliver(int $userId, string $type, string $title, string $body, ?string $url): int
    {
        $subs = $this->subscriptions->forUser($userId);
        if ($subs === []) {
            return 0;
        }

        // Default the tap target to the type's card deep link, so tapping opens a fresh
        // chat showing the relevant card. An explicit $url still wins.
        $url ??= NotificationTypes::deepLink($type);

        $payload = json_encode(
            ['title' => $title, 'body' => $body, 'url' => $url ?? '/', 'type' => $type],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $sent = 0;
        foreach ($subs as $s) {
            $result = $this->sender->send($s, (string) $payload);
            if ($result === 'ok') {
                $this->subscriptions->touch($s['id']);
                $sent++;
            } elseif ($result === 'expired') {
                $this->subscriptions->deleteByEndpoint($s['endpoint']);
            }
            // 'failed' is transient — leave the subscription in place.
        }

        return $sent;
    }
}
