<?php

namespace App\Wallet\Support;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Encryption with WALLET_KEY, never APP_KEY (docs/phase-7-hr-attendance-bonus-wallet.md §8): the authenticator secret
 * and evidence files. Company-side code, holding only APP_KEY, can't decrypt either.
 */
final class WalletCrypt
{
    private static ?Encrypter $encrypter = null;

    private static ?string $forKey = null;

    public static function encrypter(): Encrypter
    {
        $configured = (string) config('wallet.key');
        if ($configured === '') {
            throw new RuntimeException('WALLET_KEY is not set.');
        }
        if (self::$encrypter === null || self::$forKey !== $configured) {
            $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;
            if ($key === false || strlen($key) !== 32) {
                throw new RuntimeException('WALLET_KEY must be 32 bytes (base64:...).');
            }
            if (hash_equals((string) config('app.key'), $configured)) {
                throw new RuntimeException('WALLET_KEY must not be APP_KEY.');
            }
            self::$encrypter = new Encrypter($key, 'AES-256-GCM');
            self::$forKey = $configured;
        }

        return self::$encrypter;
    }

    public static function encrypt(string $plain): string
    {
        return self::encrypter()->encryptString($plain);
    }

    public static function decrypt(string $payload): string
    {
        return self::encrypter()->decryptString($payload);
    }
}
