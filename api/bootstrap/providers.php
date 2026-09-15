<?php

use App\Providers\AppServiceProvider;
use App\Wallet\WalletServiceProvider;

return [
    AppServiceProvider::class,
    // The super admin wallet (docs/phase-7-hr-attendance-bonus-wallet.md §8).
    WalletServiceProvider::class,
];
