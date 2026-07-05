<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Symmetric encryption at rest using libsodium's secretbox (authenticated
 * XSalsa20-Poly1305). Same scheme and key (APP_ENCRYPTION_KEY) already used for
 * the Google refresh token — now shared so other columns (e.g. personal memory
 * facts) can be encrypted the same way.
 *
 * Storage format: base64(nonce . ciphertext). Decryption failure throws — it
 * never falls back to raw bytes.
 */
final class Encryptor
{
    /** Env var holding the base64-encoded 32-byte secretbox key. */
    private const KEY_ENV = 'APP_ENCRYPTION_KEY';

    public function encrypt(string $plaintext): string
    {
        $key    = $this->key();
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $stored): string
    {
        $key  = $this->key();
        $data = base64_decode($stored, true);

        if ($data === false || strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Ciphertext is malformed.');
        }

        $nonce  = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plaintext === false) {
            // Wrong key or tampered ciphertext — never fall back to raw bytes.
            throw new RuntimeException('Value could not be decrypted.');
        }

        return $plaintext;
    }

    private function key(): string
    {
        $raw = $_ENV[self::KEY_ENV] ?? null;

        if ($raw === null || $raw === '') {
            throw new RuntimeException('Missing required environment variable: ' . self::KEY_ENV);
        }

        $key = base64_decode((string) $raw, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                self::KEY_ENV . ' must be a base64-encoded ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . '-byte key.'
            );
        }

        return $key;
    }
}
