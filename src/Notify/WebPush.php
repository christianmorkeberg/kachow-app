<?php

declare(strict_types=1);

namespace App\Notify;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as MinishlinkWebPush;
use RuntimeException;

/**
 * Web Push transport backed by minishlink/web-push, signing with our VAPID keys.
 * The VAPID public key is safe to expose to the browser; the private key is a
 * secret in .env (never committed, kept stable like APP_ENCRYPTION_KEY).
 */
final class WebPush implements Sender
{
    private MinishlinkWebPush $client;

    public function __construct(string $publicKey, string $privateKey, string $subject)
    {
        $this->client = new MinishlinkWebPush([
            'VAPID' => ['subject' => $subject, 'publicKey' => $publicKey, 'privateKey' => $privateKey],
        ]);
    }

    public static function fromEnv(): self
    {
        $public  = self::env('VAPID_PUBLIC_KEY');
        $private = self::env('VAPID_PRIVATE_KEY');
        $subject = self::env('VAPID_SUBJECT') ?: 'mailto:admin@example.com';
        if ($public === '' || $private === '') {
            throw new RuntimeException('VAPID keys are not configured (set VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY).');
        }

        return new self($public, $private, $subject);
    }

    public static function isConfigured(): bool
    {
        return self::env('VAPID_PUBLIC_KEY') !== '' && self::env('VAPID_PRIVATE_KEY') !== '';
    }

    public static function publicKey(): string
    {
        return self::env('VAPID_PUBLIC_KEY');
    }

    public function send(array $subscription, string $payloadJson): string
    {
        $sub = Subscription::create([
            'endpoint' => $subscription['endpoint'],
            'keys'     => ['p256dh' => $subscription['p256dh'], 'auth' => $subscription['auth']],
        ]);

        $report = $this->client->sendOneNotification($sub, $payloadJson);
        if ($report->isSuccess()) {
            return 'ok';
        }

        return $report->isSubscriptionExpired() ? 'expired' : 'failed';
    }

    private static function env(string $key): string
    {
        $v = $_ENV[$key] ?? getenv($key);

        return is_string($v) ? trim($v) : '';
    }
}
