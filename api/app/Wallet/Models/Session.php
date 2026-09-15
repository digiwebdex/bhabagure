<?php

namespace App\Wallet\Models;

/**
 * A wallet sign-in token, stored as its SHA-256: a `challenge` between the password and the authenticator code (a few
 * minutes, a few tries), then a `session` held in the wallet's own cookie. Never the admin's token or cookie.
 */
class Session extends WalletModel
{
    public const UPDATED_AT = null;

    public const CHALLENGE = 'challenge';

    public const SESSION = 'session';

    protected $table = 'wallet_sessions';

    protected $fillable = ['kind', 'token_hash', 'staff_id', 'expires_at', 'last_seen_at', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime', 'created_at' => 'datetime', 'failed_codes' => 'integer'];
    }
}
