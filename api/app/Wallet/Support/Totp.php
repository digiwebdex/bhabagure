<?php

namespace App\Wallet\Support;

use InvalidArgumentException;

/**
 * Time-based one-time codes (RFC 6238: HMAC-SHA1, 30-second steps, 6 digits), as any authenticator app makes them. The
 * wallet's second factor (docs/phase-7-hr-attendance-bonus-wallet.md §8).
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new secret: 20 random bytes in base32, the form an authenticator app is given. */
    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(int $unixTime): int
    {
        return intdiv($unixTime, self::PERIOD);
    }

    public static function code(string $base32Secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($base32Secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The time step a code belongs to, allowing one step either side for clock drift; null when it matches none. The
     * caller refuses a step it has already accepted, so a code works once.
     */
    public static function verify(string $base32Secret, string $code, int $unixTime): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }
        $now = self::step($unixTime);
        foreach ([$now, $now - 1, $now + 1] as $step) {
            if (hash_equals(self::code($base32Secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** The otpauth:// address an authenticator app reads from a QR code. */
    public static function uri(string $base32Secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account)
            .'?secret='.$base32Secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $base32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($base32, '='))) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                throw new InvalidArgumentException('Not a base32 secret.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
