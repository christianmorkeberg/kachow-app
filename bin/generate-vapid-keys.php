<?php

declare(strict_types=1);

/**
 * One-off helper: generates a VAPID keypair for Web Push and prints the .env
 * lines to paste. Run once on the server after `composer install`:
 *
 *     php bin/generate-vapid-keys.php
 *
 * Keep the private key SECRET and STABLE (like APP_ENCRYPTION_KEY) — rotating it
 * invalidates every existing push subscription.
 */

require __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "Add these to your .env (keep VAPID_PRIVATE_KEY secret):\n\n";
echo 'VAPID_PUBLIC_KEY=' . $keys['publicKey'] . "\n";
echo 'VAPID_PRIVATE_KEY=' . $keys['privateKey'] . "\n";
echo "VAPID_SUBJECT=mailto:admin@example.com\n";
