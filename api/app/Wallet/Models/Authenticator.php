<?php

namespace App\Wallet\Models;

use App\Wallet\Support\WalletCrypt;

/** The super admin's authenticator app: its secret (encrypted with WALLET_KEY) and the last time step accepted. */
class Authenticator extends WalletModel
{
    protected $table = 'wallet_authenticators';

    protected $fillable = ['staff_id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'last_used_step' => 'integer'];
    }

    public function plainSecret(): string
    {
        return WalletCrypt::decrypt($this->secret);
    }

    public function setPlainSecret(string $secret): void
    {
        $this->secret = WalletCrypt::encrypt($secret);
    }
}
