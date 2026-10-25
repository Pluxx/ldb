<?php

declare(strict_types=1);

namespace Ldb\Auth;

/**
 * AES-256-GCM authenticated encryption helpers.
 * Ciphertext format: base64( iv[12] || tag[16] || ciphertext ).
 */
final class Crypto
{
    public const CIPHER = 'aes-256-gcm';
    public const KEY_BYTES = 32;
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function generateKey(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    public static function encrypt(string $plaintext, string $key, string $aad = ''): string
    {
        self::assertKey($key);
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_BYTES);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    /** Returns null when the payload is malformed, tampered with or the key is wrong. */
    public static function decrypt(string $payload, string $key, string $aad = ''): ?string
    {
        if (strlen($key) !== self::KEY_BYTES) {
            return null;
        }
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }
        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $cipher = substr($raw, self::IV_BYTES + self::TAG_BYTES);
        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        return $plain === false ? null : $plain;
    }

    /** URL-safe random token. */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException('Key must be exactly ' . self::KEY_BYTES . ' bytes');
        }
    }
}
