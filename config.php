<?php

declare(strict_types=1);

/**
 * Application bootstrap: loads secrets from .env into $_ENV.
 *
 * Loaded immediately after vendor/autoload.php by every entry point:
 *
 *     require '/home/kachowdk/assistant-app/vendor/autoload.php';
 *     require '/home/kachowdk/assistant-app/config.php';
 *
 * .env lives in this directory (outside the webroot) and is never committed.
 * After this runs, $_ENV holds DB_HOST, DB_NAME, DB_USER, DB_PASS,
 * APP_ENCRYPTION_KEY, GEMINI_API_KEY, GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET.
 */

use Dotenv\Dotenv;

// createImmutable: values already present in the real environment win and are
// not overwritten, which is the safe default for shared hosting.
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// UTC everywhere; the assistant converts to the user's local time as needed.
date_default_timezone_set('UTC');
