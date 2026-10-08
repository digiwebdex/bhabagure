<?php

namespace App\Wallet\Services;

use App\Enums\StaffStatus;
use App\Models\Staff;
use App\Wallet\Models\AuditLog;
use App\Wallet\Models\Member;
use App\Wallet\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Wallet sign-in (docs/personal-wallets.md): the person's staff email and password open a wallet session of its own —
 * the admin's access token never opens the wallet, and the wallet's cookie never opens the admin. The authenticator code
 * that used to follow the password was taken out on 2026-10-09 at the owner's request; sign-in attempts stay rate-limited
 * (App\Wallet\WalletServiceProvider).
 *
 * Reads the staff account to check the password; writes only to the wallet database.
 */
final class WalletAuth
{
    /**
     * The same refusal for an unknown email, a wrong password or someone without a wallet. Returns the session token for
     * the cookie.
     *
     * @throws WalletRefused
     */
    public function password(string $email, string $password, Request $request): string
    {
        $staff = Staff::query()->where('email', trim($email))->first();
        if ($staff === null || ! Hash::check($password, $staff->password) || ! self::allowed($staff)) {
            AuditLog::record('sign_in_refused', $staff?->id);
            throw new WalletRefused('invalid_credentials', 401);
        }

        $token = bin2hex(random_bytes(32));
        Session::query()->create([
            'kind' => Session::SESSION, 'token_hash' => hash('sha256', $token), 'staff_id' => $staff->id,
            'expires_at' => now()->addMinutes((int) config('wallet.session.absolute_minutes')), 'last_seen_at' => now(),
            'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        AuditLog::record('signed_in', $staff->id);

        return $token;
    }

    /** The staff member a session cookie belongs to, while it is live; touches it. */
    public function resolve(?string $token): ?Staff
    {
        if ($token === null || strlen($token) !== 64) {
            return null;
        }
        $session = Session::query()->where('token_hash', hash('sha256', $token))->where('kind', Session::SESSION)->first();
        $idleSince = now()->subMinutes((int) config('wallet.session.idle_minutes'));
        if ($session === null || $session->revoked_at !== null || $session->expires_at->isPast() || $session->last_seen_at === null || $session->last_seen_at->lt($idleSince)) {
            return null;
        }
        $staff = Staff::query()->find($session->staff_id);
        if ($staff === null || ! self::allowed($staff)) {
            return null;
        }
        // One write a minute is enough to keep it alive.
        if ($session->last_seen_at->lt(now()->subMinute())) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }

        return $staff;
    }

    public function signOut(?string $token): void
    {
        if ($token === null) {
            return;
        }
        $session = Session::query()->where('token_hash', hash('sha256', $token))->where('kind', Session::SESSION)->whereNull('revoked_at')->first();
        if ($session !== null) {
            $session->forceFill(['revoked_at' => now()])->save();
            AuditLog::record('signed_out', $session->staff_id);
        }
    }

    /**
     * The super admin, and staff the super admin gave a wallet to (docs/personal-wallets.md), while their account is active.
     * Revoking a wallet ends its sessions at the next request.
     */
    public static function allowed(Staff $staff): bool
    {
        return $staff->status === StaffStatus::Active && ($staff->isSuperAdmin() || Member::hasAccess($staff->id));
    }
}
