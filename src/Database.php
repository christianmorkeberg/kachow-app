<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO connection factory.
 *
 * Holds a single shared PDO instance for the request lifetime. Credentials are
 * read from $_ENV, which config.php populates from .env via vlucas/phpdotenv
 * before any Data/ class is used.
 */
final class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    /**
     * Returns the shared PDO connection, creating it on first call.
     */
    public static function get(): PDO
    {
        if (self::$instance === null) {
            // Time the (once-per-request) connection so DB connect overhead is visible.
            $t0             = microtime(true);
            self::$instance = self::connect();
            error_log(sprintf('timing db-connect: %dms', (int) round((microtime(true) - $t0) * 1000)));
        }

        return self::$instance;
    }

    private static function connect(): PDO
    {
        $host = self::env('DB_HOST');
        $name = self::env('DB_NAME');
        $user = self::env('DB_USER');
        $pass = self::env('DB_PASS');
        $port = $_ENV['DB_PORT'] ?? '3306';

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $name
        );

        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Never surface the DSN/credentials in the message.
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
    }

    /**
     * Reads a required environment variable, failing loudly if it is missing.
     */
    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? null;

        if ($value === null || $value === '') {
            throw new RuntimeException("Missing required environment variable: {$key}");
        }

        return (string) $value;
    }
}
