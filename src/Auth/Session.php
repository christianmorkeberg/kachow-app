<?php

declare(strict_types=1);

namespace App\Auth;

use App\Data\Users;

/**
 * The app's own session-based login (separate from Google OAuth).
 *
 * Verifies email + password against users.password_hash and tracks the logged-in
 * user id in $_SESSION. "Remember me" is a separate concern handled by RememberMe.
 */
final class Session
{
    private const KEY = 'user_id';

    /** How long a login session lasts: the cookie and the server-side data, sliding with every request. */
    public const LIFETIME_DAYS = 30;

    public function __construct(private Users $users)
    {
    }

    /**
     * Starts the PHP session for this request. Call once, before any output.
     * No-op under CLI (tests drive $_SESSION directly).
     */
    public function boot(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(self::options(null, self::isHttps()));
        }
    }

    /**
     * Options for session_start. A long lifetime for BOTH the cookie (otherwise it ends with the browser session) and the
     * server-side data (otherwise PHP's default ~24 minutes of idle time throws the session away). The data lives in an own
     * folder next to the app — the host's shared session folder is cleaned on the host's schedule, whatever we ask for here —
     * and this app cleans it itself (1% of requests).
     *
     * @return array<string, mixed>
     */
    public static function options(?string $saveDir = null, bool $https = false): array
    {
        $seconds = self::LIFETIME_DAYS * 86400;
        $opts    = [
            'cookie_httponly' => true,
            'cookie_secure'   => $https,
            'cookie_samesite' => 'Lax',
            'cookie_lifetime' => $seconds,
            'gc_maxlifetime'  => $seconds,
            'gc_probability'  => 1,
            'gc_divisor'      => 100,
        ];
        $dir = $saveDir ?? dirname(__DIR__, 3) . '/sessions';
        if (is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir)) {
            if (is_writable($dir)) {
                $opts['save_path'] = $dir;
            }
        }

        return $opts;
    }

    /**
     * Verifies credentials and, on success, marks the session as logged in.
     * Returns true on success, false on unknown email or bad password.
     */
    public function login(string $email, string $password): bool
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            // Still spend a hash to keep timing between "no user" and "bad
            // password" roughly comparable.
            password_verify($password, '$2y$10$usesomesillystringforsalt0000000000000000000000000000');
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        $this->establish((int) $user['id']);
        return true;
    }

    /**
     * Marks the session as logged in for a user id, without a password check.
     * Used by RememberMe auto-login after a valid cookie is presented.
     */
    public function establish(int $userId): void
    {
        // Prevent session fixation: new id on privilege change.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::KEY] = $userId;
    }

    public function userId(): ?int
    {
        return isset($_SESSION[self::KEY]) ? (int) $_SESSION[self::KEY] : null;
    }

    public function isLoggedIn(): bool
    {
        return $this->userId() !== null;
    }

    /**
     * Clears the login. Does NOT touch remember-me tokens — the caller should
     * also call RememberMe::forget() to fully log out.
     */
    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private static function isHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
    }
}
