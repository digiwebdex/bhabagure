<?php

namespace App\Wallet\Models;

/**
 * A wallet session token, stored as its SHA-256 and held in the wallet's own cookie. Never the admin's token or cookie.
 * (Rows of kind `challenge`, the step before the authenticator code, are no longer made since 2026-10-09.)
 */
class Session extends WalletModel
{
    public const UPDATED_AT = null;

    public const SESSION = 'session';

    protected $table = 'wallet_sessions';

    protected $fillable = ['kind', 'token_hash', 'staff_id', 'expires_at', 'last_seen_at', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime', 'created_at' => 'datetime', 'failed_codes' => 'integer'];
    }
}
