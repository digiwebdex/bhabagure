<?php

namespace App\Wallet\Services;

use App\Enums\StaffStatus;
use App\Models\Staff;
use App\Wallet\Models\AuditLog;
use App\Wallet\Models\Authenticator;
use App\Wallet\Models\Session;
use App\Wallet\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Wallet sign-in (docs/phase-7-hr-attendance-bonus-wallet.md §8): the super admin's staff email and password, then a code
 * from their authenticator app. The first time, the app is enrolled with that same code. The result is a wallet session
 * of its own — the admin's access token never opens the wallet, and the wallet's cookie never opens the admin.
 *
 * Reads the staff account to check the password; writes only to the wallet database.
 */
final class WalletAuth
{
    /** Wrong codes one challenge allows before it is spent. */
    public const MAX_FAILED_CODES = 5;

    /**
     * Step one. The same refusal for an unknown email, a wrong password or someone who isn't the super admin.
     *
     * @return array{challenge: string, enrolled: bool, enrollment: array{secret: string, uri: string}|null}
     *
     * @throws WalletRefused
     */
    public function password(string $email, string $password, Request $request): array
    {
        $staff = Staff::query()->where('email', trim($email))->first();
        if ($staff === null || ! Hash::check($password, $staff->password) || ! self::allowed($staff)) {
            AuditLog::record('sign_in_refused', $staff?->id, null, ['step' => 'password']);
            throw new WalletRefused('invalid_credentials', 401);
        }

        return DB::connection('wallet')->transaction(function () use ($staff, $request) {
            $authenticator = Authenticator::query()->where('staff_id', $staff->id)->lockForUpdate()->first();
            $enrollment = null;
            if ($authenticator === null || $authenticator->confirmed_at === null) {
                // Not enrolled yet: a fresh secret each time until a code from it is accepted.
                $authenticator ??= new Authenticator(['staff_id' => $staff->id]);
                $secret = Totp::secret();
                $authenticator->setPlainSecret($secret);
                $authenticator->save();
                $enrollment = ['secret' => $secret, 'uri' => Totp::uri($secret, $staff->email, (string) config('wallet.issuer'))];
            }

            // Older challenges for this person end here.
            Session::query()->where('staff_id', $staff->id)->where('kind', Session::CHALLENGE)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token = bin2hex(random_bytes(32));
            Session::query()->create([
                'kind' => Session::CHALLENGE, 'token_hash' => hash('sha256', $token), 'staff_id' => $staff->id,
                'expires_at' => now()->addMinutes((int) config('wallet.session.challenge_minutes')),
                'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);

            return ['challenge' => $token, 'enrolled' => $enrollment === null, 'enrollment' => $enrollment];
        });
    }

    /**
     * Step two: the authenticator code for a live challenge. Returns the session token for the cookie.
     *
     * @throws WalletRefused
     */
    public function code(string $challengeToken, string $code, Request $request): string
    {
        // A wrong code is counted and logged in a committed transaction, then refused.
        $result = DB::connection('wallet')->transaction(function () use ($challengeToken, $code, $request) {
            $challenge = Session::query()->where('token_hash', hash('sha256', $challengeToken))->where('kind', Session::CHALLENGE)->lockForUpdate()->first();
            if ($challenge === null || $challenge->revoked_at !== null || $challenge->expires_at->isPast()) {
                throw new WalletRefused('challenge_expired', 401);
            }
            $staff = Staff::query()->find($challenge->staff_id);
            $authenticator = Authenticator::query()->where('staff_id', $challenge->staff_id)->lockForUpdate()->first();
            if ($staff === null || ! self::allowed($staff) || $authenticator === null) {
                throw new WalletRefused('challenge_expired', 401);
            }

            $step = Totp::verify($authenticator->plainSecret(), $code, now()->getTimestamp());
            // A code works once: a step at or before the last one accepted is refused.
            if ($step === null || ($authenticator->last_used_step !== null && $step <= $authenticator->last_used_step)) {
                $failed = $challenge->failed_codes + 1;
                $challenge->forceFill(['failed_codes' => $failed, 'revoked_at' => $failed >= self::MAX_FAILED_CODES ? now() : null])->save();
                AuditLog::record('sign_in_refused', $staff->id, null, ['step' => 'code', 'failed' => $failed]);

                return ['refused' => $failed >= self::MAX_FAILED_CODES ? 'challenge_expired' : 'invalid_code'];
            }

            $enrolledNow = $authenticator->confirmed_at === null;
            $authenticator->forceFill(['last_used_step' => $step, 'confirmed_at' => $authenticator->confirmed_at ?? now()])->save();
            $challenge->forceFill(['revoked_at' => now()])->save();

            $token = bin2hex(random_bytes(32));
            Session::query()->create([
                'kind' => Session::SESSION, 'token_hash' => hash('sha256', $token), 'staff_id' => $staff->id,
                'expires_at' => now()->addMinutes((int) config('wallet.session.absolute_minutes')), 'last_seen_at' => now(),
                'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
            if ($enrolledNow) {
                AuditLog::record('authenticator_enrolled', $staff->id);
            }
            AuditLog::record('signed_in', $staff->id);

            return ['token' => $token];
        });

        if (isset($result['refused'])) {
            throw new WalletRefused($result['refused'], 401);
        }

        return $result['token'];
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

    /** Removes someone's authenticator and ends their sessions: the next sign-in enrolls a new one (wallet:reset-authenticator). */
    public function resetAuthenticator(Staff $staff): void
    {
        DB::connection('wallet')->transaction(function () use ($staff) {
            Authenticator::query()->where('staff_id', $staff->id)->delete();
            Session::query()->where('staff_id', $staff->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            AuditLog::record('authenticator_reset', $staff->id);
        });
    }

    /** The wallet is the super admin's alone, and only while their account is active. */
    public static function allowed(Staff $staff): bool
    {
        return $staff->status === StaffStatus::Active && $staff->isSuperAdmin();
    }
}
