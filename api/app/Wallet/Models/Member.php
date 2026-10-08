<?php

namespace App\Wallet\Models;

/**
 * Someone the super admin gave a wallet to (docs/personal-wallets.md). Revoking keeps the row, with who and when; giving
 * the wallet back clears it. The super admin has a wallet without a row.
 */
class Member extends WalletModel
{
    protected $table = 'wallet_members';

    protected $fillable = ['staff_id', 'granted_by_staff_id', 'granted_at', 'revoked_by_staff_id', 'revoked_at'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function hasAccess(int $staffId): bool
    {
        return self::query()->where('staff_id', $staffId)->whereNull('revoked_at')->exists();
    }
}
