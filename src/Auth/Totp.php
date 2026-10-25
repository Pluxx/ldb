<?php

declare(strict_types=1);

namespace Ldb\Auth;

/**
 * Time-based one-time passwords (RFC 6238 on top of HOTP, RFC 4226): HMAC-SHA1, 6 digits,
 * 30 second steps, ±1 step tolerance. Secrets are 20 random bytes, exchanged as base32
 * (RFC 4648, no padding) as authenticator apps expect.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    public const WINDOW = 1;
    public const SECRET_BYTES = 20;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** New random secret, base32 encoded (32 characters). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /** Time step (counter) for a unix timestamp. */
    public static function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /** HOTP value for a raw (binary) key and counter, zero-padded to $digits. */
    public static function hotp(string $key, int $counter, int $digits = self::DIGITS): string
    {
        $msg = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $msg, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** TOTP value of a base32 secret at a unix time. */
    public static function code(string $secret, int $time): string
    {
        $key = self::base32Decode($secret);
        if ($key === null || $key === '') {
            throw new \InvalidArgumentException('Invalid base32 secret');
        }
        return self::hotp($key, self::step($time));
    }

    /** Strip spaces/dashes a user may type or paste; returns null unless exactly 6 digits remain. */
    public static function normalizeCode(string $code): ?string
    {
        $c = preg_replace('/[\s\-]+/', '', $code) ?? '';
        return preg_match('/^\d{' . self::DIGITS . '}$/D', $c) ? $c : null;
    }

    /**
     * Check a code against the steps now-WINDOW … now+WINDOW. Steps at or before $lastStep
     * (the last accepted one) never match, so a code cannot be used twice (RFC 6238 §5.2).
     * All candidate steps are compared with hash_equals() (no early exit, constant work).
     * @return int|null the matching time step, or null
     */
    public static function verify(string $secret, string $code, int $time, ?int $lastStep = null, int $window = self::WINDOW): ?int
    {
        $code = self::normalizeCode($code);
        $key = self::base32Decode($secret);
        if ($code === null || $key === null || $key === '') {
            return null;
        }
        $now = self::step($time);
        $match = null;
        for ($s = $now - $window; $s <= $now + $window; $s++) {
            $ok = hash_equals(self::hotp($key, $s), $code);
            if ($ok && $match === null && ($lastStep === null || $s > $lastStep)) {
                $match = $s;
            }
        }
        return $match;
    }

    /** otpauth:// provisioning URI (Key Uri Format used by Google Authenticator & co). */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(string $raw): string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($raw[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::B32[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $out .= self::B32[($buffer << (5 - $bits)) & 31];
        }
        return $out;
    }

    /** Decode base32 (case-insensitive, spaces and '=' padding ignored); null when invalid. */
    public static function base32Decode(string $b32): ?string
    {
        $b32 = strtoupper(str_replace([' ', '='], '', $b32));
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $v = strpos(self::B32, $b32[$i]);
            if ($v === false) {
                return null;
            }
            $buffer = ($buffer << 5) | $v;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
            $buffer &= (1 << $bits) - 1;
        }
        return $out;
    }
}
