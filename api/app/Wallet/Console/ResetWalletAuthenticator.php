<?php

namespace App\Wallet\Console;

use App\Models\Staff;
use App\Wallet\Services\WalletAuth;
use Illuminate\Console\Command;

/**
 * For a lost or replaced phone: removes the super admin's wallet authenticator and ends their wallet sessions. The next
 * wallet sign-in enrolls a new authenticator app. Run on the server by the proprietor.
 */
final class ResetWalletAuthenticator extends Command
{
    protected $signature = 'wallet:reset-authenticator {email : The super admin\'s sign-in email}';

    protected $description = 'Remove the wallet authenticator for a super admin; the next wallet sign-in enrolls a new one';

    public function handle(WalletAuth $auth): int
    {
        $staff = Staff::query()->where('email', trim((string) $this->argument('email')))->first();
        if ($staff === null || ! WalletAuth::allowed($staff)) {
            $this->error('No active super admin with that email.');

            return self::FAILURE;
        }
        if (! $this->confirm("Remove the wallet authenticator for {$staff->name} and sign out their wallet sessions?", true)) {
            return self::FAILURE;
        }
        $auth->resetAuthenticator($staff);
        $this->info('Done. The next wallet sign-in shows a new QR code to scan.');

        return self::SUCCESS;
    }
}
