<?php

declare(strict_types=1);

/**
 * Every 15 minutes: the upkeep of the recruiter chat. (1) A message a recruiter left that neither the push nor the email reached the owner with is tried again
 * (the message itself was saved first, so it is never lost). (2) Once a night (03:xx) chat logs older than 14 days are deleted — the left messages stay.
 *
 *     cron: every 15 minutes  php bin/recruiter-cron.php   (or let the per-minute dispatcher run it)
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

\App\Support\SystemTaskGuard::begin('recruiter');

use App\Recruiter\LeadDelivery;
use App\Recruiter\RecruiterLinks;

$links     = new RecruiterLinks();
$retried   = LeadDelivery::fromEnv($links)->retryUndelivered();
$purged    = (int) date('G') === 3 && (int) date('i') < 15 ? $links->purge() : 0;
echo "recruiter: {$retried} message(s) delivered on retry, {$purged} old chat message(s) deleted.\n";
