<?php

declare(strict_types=1);

/**
 * Loads the facts the recruiter assistant may use into the database, from a JSON file kept OUTSIDE this (public) repository — the facts are personal, so they live in the
 * private docs repo and are copied to the server by hand. REPLACES the owner's entries with the file's.
 *
 *     php bin/recruiter-import.php /path/to/entries.json [owner user id, default 1]
 *
 * File format: a JSON array of {category, title, body, summary?, visibility: public|summary|private, lang?, sort?}. Edit visibility afterwards by chatting with Kachow
 * ("make the freelance entry private") — the update_about_entry tool.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Recruiter\AboutEntries;

$file  = $argv[1] ?? '';
$owner = isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : 1;
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/recruiter-import.php /path/to/entries.json [owner user id]\n");
    exit(2);
}
$data = json_decode((string) file_get_contents($file), true);
if (!is_array($data) || $data === []) {
    fwrite(STDERR, "The file is not a non-empty JSON array.\n");
    exit(2);
}
$counts = ['public' => 0, 'summary' => 0, 'private' => 0];
foreach ($data as $e) {
    $v = (string) ($e['visibility'] ?? 'private');
    $counts[isset($counts[$v]) ? $v : 'private']++;
}
$n = (new AboutEntries())->replaceAll($owner, $data);
echo "Imported {$n} entries for user {$owner}: {$counts['public']} public, {$counts['summary']} summary-only, {$counts['private']} private.\n";
