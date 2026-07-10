<?php

declare(strict_types=1);

namespace App\Notify;

/**
 * Low-level push transport. Abstracted so Notifier can be unit-tested with a fake
 * and doesn't depend on the concrete web-push library.
 */
interface Sender
{
    /**
     * Delivers a JSON payload to one subscription.
     *
     * @param array{endpoint:string, p256dh:string, auth:string} $subscription
     * @return string one of 'ok', 'expired' (subscription gone — caller should delete), 'failed'
     */
    public function send(array $subscription, string $payloadJson): string;
}
