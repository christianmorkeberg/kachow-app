<?php

declare(strict_types=1);

// The shared host's `php` can be the CGI binary, which doesn't define the CLI stream constants;
// the cron scripts log with fwrite(STDERR, ...), so define them when missing.
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'w'));
}

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
 * Optional: ORS_API_KEY (OpenRouteService) enables the mileage address→distance lookup.
 */

use Dotenv\Dotenv;

// createImmutable: values already present in the real environment win and are
// not overwritten, which is the safe default for shared hosting.
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// UTC everywhere; the assistant converts to the user's local time as needed.
date_default_timezone_set('UTC');

// json_encode writes floats with the shortest exact digits (4.44, not 4.44000000000000039…). The server's php.ini had serialize_precision = 17, which bloated every tool result
// the model reads and overflowed a JSON column (report #84).
ini_set('serialize_precision', '-1');
