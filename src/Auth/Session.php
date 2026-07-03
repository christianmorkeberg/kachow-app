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
            session_start([
                'cookie_httponly' => true,
                'cookie_secure'   => self::isHttps(),
                'cookie_samesite' => 'Lax',
            ]);
        }
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
