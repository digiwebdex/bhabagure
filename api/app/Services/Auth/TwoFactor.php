<?php

namespace App\Services\Auth;

use App\Models\SiteSetting;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Two-step sign-in with an authenticator app (docs/two-step-sign-in.md): the admin and the personal wallet ask for a
 * 6-digit code after the password, for everyone who turned it on in their profile, or for everyone when the super admin
 * requires it. Each person's secret is kept encrypted (Staff casts); a code works once; ten recovery codes, kept as keyed
 * hashes, replace a lost phone, and the super admin can reset someone's.
 */
final class TwoFactor
{
    public const RECOVERY_CODES = 10;

    /** Wrong codes for one account, from anywhere, before it waits: on top of the sign-in's own per-address limit. */
    public const FAILURES_ALLOWED = 10;

    public const FAILURE_WINDOW_SECONDS = 900;

    /** A private site setting, never in the public settings (SiteSettingKeys::PUBLIC). */
    public const POLICY_KEY = 'staff_security';

    private const ISSUER = 'Bhabaghure';

    public function __construct(private readonly AuditLogger $audit, private readonly RefreshTokens $tokens) {}

    /** The super admin's switch: everyone with a login must use it. */
    public static function required(): bool
    {
        return (bool) (((array) SiteSetting::get(self::POLICY_KEY, []))['two_factor_required'] ?? false);
    }

    /** Signed in, but not set up while it is required: the admin lets them do nothing else first (EnsureStaffCanWork). */
    public static function mustSetUp(Staff $staff): bool
    {
        return self::required() && ! $staff->hasTwoFactor();
    }

    public function setRequired(bool $required, Staff $by): void
    {
        $value = (array) SiteSetting::get(self::POLICY_KEY, []);
        SiteSetting::query()->updateOrCreate(['key' => self::POLICY_KEY], ['value' => ['two_factor_required' => $required] + $value]);
        $this->audit->record('auth.two_factor.policy', $by, null, ['required' => $required]);
    }

    /**
     * A new secret for the person to scan; not in force until a code from it is confirmed. Starting again replaces one
     * not confirmed yet.
     *
     * @return array{secret: string, uri: string}
     *
     * @throws TwoFactorRefused
     */
    public function begin(Staff $staff): array
    {
        if ($staff->hasTwoFactor()) {
            throw new TwoFactorRefused('already_on');
        }
        $secret = Totp::secret();
        $staff->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $staff->email, self::ISSUER)];
    }

    /**
     * The first code from the app puts it in force, and the recovery codes are made: returned once, never shown again.
     *
     * @return list<string>
     *
     * @throws TwoFactorRefused
     */
    public function confirm(Staff $staff, string $code): array
    {
        if ($staff->hasTwoFactor()) {
            throw new TwoFactorRefused('already_on');
        }
        if ($staff->two_factor_secret === null) {
            throw new TwoFactorRefused('not_started');
        }
        $this->ensureNotLockedOut($staff);
        $step = Totp::verify((string) $staff->two_factor_secret, $code, now()->getTimestamp());
        if ($step === null) {
            $this->failed($staff);
            throw new TwoFactorRefused('invalid_code');
        }
        [$plain, $hashes] = self::makeRecoveryCodes();
        $staff->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step, 'two_factor_recovery_codes' => $hashes])->save();
        $this->audit->record('auth.two_factor.enabled', $staff, $staff);

        return $plain;
    }

    /**
     * New recovery codes, with a code from the app; the old ones stop working.
     *
     * @return list<string>
     *
     * @throws TwoFactorRefused
     */
    public function newRecoveryCodes(Staff $staff, string $code): array
    {
        $this->ensureOn($staff);
        if ($this->check($staff, $code, allowRecovery: false) === null) {
            throw new TwoFactorRefused('invalid_code');
        }
        [$plain, $hashes] = self::makeRecoveryCodes();
        $staff->forceFill(['two_factor_recovery_codes' => $hashes])->save();
        $this->audit->record('auth.two_factor.recovery_codes_renewed', $staff, $staff);

        return $plain;
    }

    /**
     * Turning it off takes the password and a code (or a recovery code). Not while the super admin requires it: then
     * only the super admin's reset clears it, and the next sign-in sets it up again.
     *
     * @throws TwoFactorRefused
     */
    public function disable(Staff $staff, string $password, string $code): void
    {
        $this->ensureOn($staff);
        if (self::required()) {
            throw new TwoFactorRefused('required_by_policy');
        }
        if (! Hash::check($password, $staff->password)) {
            throw new TwoFactorRefused('wrong_password');
        }
        if ($this->check($staff, $code) === null) {
            throw new TwoFactorRefused('invalid_code');
        }
        $this->clear($staff);
        $this->audit->record('auth.two_factor.disabled', $staff, $staff);
    }

    /**
     * The super admin clears someone's (a lost phone, no recovery codes left): they are signed out everywhere, and sign in
     * again with the password alone, or set it up again at once while it is required.
     *
     * @throws TwoFactorRefused
     */
    public function reset(Staff $target, Staff $by): void
    {
        if (! $by->isSuperAdmin()) {
            throw new TwoFactorRefused('super_admin_only');
        }
        if ($target->two_factor_secret === null && $target->two_factor_confirmed_at === null) {
            throw new TwoFactorRefused('not_on');
        }
        $this->clear($target);
        $this->tokens->revokeAllFor('staff', $target->id);
        RateLimiter::clear(self::failureKey($target));
        $this->audit->record('auth.two_factor.reset', $by, $target);
    }

    /**
     * A sign-in's second step: a code from the app, each working once (its time step is recorded, atomically, so two
     * requests can't both use it), or a recovery code, used up. Returns 'code', 'recovery', or null when neither matched.
     *
     * @throws TwoFactorRefused too many wrong codes for this account
     */
    public function check(Staff $staff, string $input, bool $allowRecovery = true): ?string
    {
        $this->ensureNotLockedOut($staff);
        $input = trim($input);
        $step = Totp::verify((string) $staff->two_factor_secret, $input, now()->getTimestamp());
        if ($step !== null) {
            $taken = Staff::query()->whereKey($staff->id)
                ->where(fn ($query) => $query->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
                ->update(['two_factor_last_step' => $step]);
            if ($taken === 1) {
                $staff->two_factor_last_step = $step;

                return 'code';
            }
        } elseif ($allowRecovery && $this->useRecoveryCode($staff, $input)) {
            return 'recovery';
        }
        $this->failed($staff);

        return null;
    }

    public static function recoveryCodesLeft(Staff $staff): int
    {
        return count((array) ($staff->two_factor_recovery_codes ?? []));
    }

    private function useRecoveryCode(Staff $staff, string $input): bool
    {
        $hash = self::hashRecoveryCode($input);
        if ($hash === null) {
            return false;
        }

        return DB::transaction(function () use ($staff, $hash) {
            $locked = Staff::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $left = array_values((array) ($locked->two_factor_recovery_codes ?? []));
            $index = array_search($hash, $left, true);
            if ($index === false) {
                return false;
            }
            array_splice($left, $index, 1);
            $locked->forceFill(['two_factor_recovery_codes' => $left])->save();
            $staff->two_factor_recovery_codes = $left;
            $this->audit->record('auth.two_factor.recovery_code_used', $staff, $staff, ['left' => count($left)]);

            return true;
        });
    }

    /** @throws TwoFactorRefused */
    private function ensureOn(Staff $staff): void
    {
        if (! $staff->hasTwoFactor()) {
            throw new TwoFactorRefused('not_on');
        }
    }

    /** @throws TwoFactorRefused */
    private function ensureNotLockedOut(Staff $staff): void
    {
        if (RateLimiter::tooManyAttempts(self::failureKey($staff), self::FAILURES_ALLOWED)) {
            throw new TwoFactorRefused('too_many_attempts', RateLimiter::availableIn(self::failureKey($staff)));
        }
    }

    private function failed(Staff $staff): void
    {
        RateLimiter::hit(self::failureKey($staff), self::FAILURE_WINDOW_SECONDS);
        $this->audit->record('auth.two_factor.wrong_code', $staff, $staff);
    }

    private function clear(Staff $staff): void
    {
        $staff->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
    }

    private static function failureKey(Staff $staff): string
    {
        return 'two-factor-failures|'.$staff->id;
    }

    /**
     * Ten codes like "k7m2-9xq4": letters and digits that can't be mistaken for each other. Stored as HMACs keyed with the
     * app key, so a copy of the database alone can't use them.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function makeRecoveryCodes(): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $plain = [];
        while (count($plain) < self::RECOVERY_CODES) {
            $raw = '';
            for ($i = 0; $i < 8; $i++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plain[] = substr($raw, 0, 4).'-'.substr($raw, 4);
            $plain = array_values(array_unique($plain));
        }

        return [$plain, array_map(fn (string $code) => (string) self::hashRecoveryCode($code), $plain)];
    }

    /** As typed: any case, with or without the dash or spaces. Null for anything that can't be a recovery code. */
    private static function hashRecoveryCode(string $input): ?string
    {
        $normal = Str::lower(preg_replace('/[\s-]+/', '', $input) ?? '');
        if (preg_match('/^[a-z0-9]{8}$/', $normal) !== 1) {
            return null;
        }

        return hash_hmac('sha256', $normal, (string) config('app.key'));
    }
}
