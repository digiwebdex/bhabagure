<?php

namespace App\Wallet\Models;

use Illuminate\Database\Eloquent\Model;

/** Every wallet model lives on the wallet connection, and only there (docs/phase-7-hr-attendance-bonus-wallet.md §8). */
abstract class WalletModel extends Model
{
    protected $connection = 'wallet';
}
