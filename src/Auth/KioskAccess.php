<?php

declare(strict_types=1);

namespace App\Auth;

use App\Data\ApiTokens;
use App\Data\RememberTokens;
use App\Data\Users;

/**
 * Who may use the kiosk (the full-screen wall display, kiosk.php + api/kiosk.php): a logged-in user (session / remember-me), or a device that
 * holds the user's KIOSK token (api_tokens scope 'kiosk') in a cookie. The token is separate from the login so a wall tablet in a shared room
 * never carries a real session, can be revoked on its own (rotate), and opens the kiosk only — not chat, email or books. What the kiosk itself
 * may do is whatever api/kiosk.php exposes (power prices, lights, thermostats).
 */
final class KioskAccess
{
    public const SCOPE  = 'kiosk';
    public const COOKIE = 'kachow_kiosk';

    /** @return array{user_id:int, via:'session'|'token'}|null */
    public static function resolve(): ?array
    {
        $users   = new Users();
        $session = new Session($users);
        $session->boot();
        if (!$session->isLoggedIn()) {
            $rememberedId = (new RememberMe(new RememberTokens()))->loginFromCookie();
            if ($rememberedId !== null) {
                $session->establish($rememberedId);
            }
        }
        if ($session->isLoggedIn()) {
            return ['user_id' => (int) $session->userId(), 'via' => 'session'];
        }

        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        $uid   = $token === '' ? null : (new ApiTokens())->userForToken($token, self::SCOPE);

        return $uid === null ? null : ['user_id' => $uid, 'via' => 'token'];
    }

    /** Checks a token from the setup link and remembers it on this device (long-lived, HTTP-only, same-site). False if it is not valid. */
    public static function adopt(string $token): bool
    {
        if ((new ApiTokens())->userForToken($token, self::SCOPE) === null) {
            return false;
        }
        setcookie(self::COOKIE, trim($token), [
            'expires'  => time() + 5 * 365 * 86400,
            'path'     => '/',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        return true;
    }
}
