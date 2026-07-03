<?php

declare(strict_types=1);

namespace App\Auth;

use App\Data\RememberTokens;
use DateTimeImmutable;
use DateTimeZone;

/**
 * "Remember me" long-lived login via a cookie holding a high-entropy random
 * token. Only a SHA-256 hash of the token is stored (in remember_tokens); the
 * raw token lives solely in the user's cookie.
 *
 * SHA-256 (fast) is appropriate here because the token is 256 bits of random —
 * not a low-entropy secret needing a slow password hash. The stored hash is
 * deterministic, so lookup is a single indexed-ish equality match.
 *
 * On each successful auto-login the token is rotated (old row deleted, new token
 * + cookie issued), limiting the window a stolen cookie stays valid.
 */
final class RememberMe
{
    private const COOKIE = 'remember';

    public function __construct(
        private RememberTokens $tokens,
        private int $ttlDays = 30,
    ) {
    }

    /**
     * Issues a fresh remember-me token for the user: stores its hash and sets
     * the cookie. Returns the raw token (chiefly so tests can inspect it).
     */
    public function remember(int $userId): string
    {
        $raw     = bin2hex(random_bytes(32));
        $hash    = self::hash($raw);
        $expires = new DateTimeImmutable("+{$this->ttlDays} days", new DateTimeZone('UTC'));

        $this->tokens->create($userId, $hash, $expires->format('Y-m-d H:i:s'));
        $this->sendCookie($raw, $expires->getTimestamp());

        return $raw;
    }

    /**
     * Attempts auto-login from the cookie. On a valid, unexpired token, rotates
     * it and returns the user id. Otherwise clears any stale cookie and returns
     * null. Does not itself establish the PHP session — the caller passes the id
     * to Session::establish().
     */
    public function loginFromCookie(): ?int
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $row = $this->tokens->findValid(self::hash($raw));
        if ($row === null) {
            $this->clearCookie();
            return null;
        }

        // Rotate: consume the presented token, issue a new one.
        $this->tokens->deleteByHash(self::hash($raw));
        $userId = (int) $row['user_id'];
        $this->remember($userId);

        return $userId;
    }

    /**
     * Invalidates the current remember-me token (deletes the row) and clears the
     * cookie. Call alongside Session::logout().
     */
    public function forget(): void
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($raw) && $raw !== '') {
            $this->tokens->deleteByHash(self::hash($raw));
        }
        $this->clearCookie();
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    private function sendCookie(string $raw, int $expiresTs): void
    {
        if (PHP_SAPI === 'cli') {
            return; // no header transport in tests
        }
        setcookie(self::COOKIE, $raw, [
            'expires'  => $expiresTs,
            'path'     => '/',
            'httponly' => true,
            'secure'   => self::isHttps(),
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $raw;
    }

    private function clearCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        if (PHP_SAPI === 'cli') {
            return;
        }
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => self::isHttps(),
            'samesite' => 'Lax',
        ]);
    }

    private static function isHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
    }
}
