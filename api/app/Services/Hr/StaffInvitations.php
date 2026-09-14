<?php

namespace App\Services\Hr;

use App\Enums\StaffStatus;
use App\Mail\StaffInvitationMail;
use App\Models\Staff;
use App\Models\StaffInvitation;
use App\Services\AuditLogger;
use App\Services\Auth\RefreshTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Links that set a staff password (docs/phase-7-hr-attendance-bonus-wallet.md §4.1). Nobody is ever given a temporary
 * password: a new account gets an invitation (72 hours; its link can also be copied once, for WhatsApp), and a forgotten
 * password gets a reset link (60 minutes) that goes only to the email on file, so no one else can take the account over.
 */
final class StaffInvitations
{
    public const INVITE_HOURS = 72;

    public const RESET_MINUTES = 60;

    public function __construct(private readonly AuditLogger $audit, private readonly RefreshTokens $tokens) {}

    /** Cancels this person's open links and issues a new one. The plain token is returned once and never stored. */
    public function issue(Staff $staff, string $purpose, ?Staff $by): string
    {
        $plain = bin2hex(random_bytes(32));

        DB::transaction(function () use ($staff, $purpose, $by, $plain) {
            StaffInvitation::query()->where('staff_id', $staff->id)->whereNull('used_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
            StaffInvitation::query()->create([
                'staff_id' => $staff->id,
                'purpose' => $purpose,
                'token_hash' => self::hash($plain),
                'expires_at' => $purpose === StaffInvitation::INVITE ? now()->addHours(self::INVITE_HOURS) : now()->addMinutes(self::RESET_MINUTES),
                'created_by_staff_id' => $by?->id,
            ]);
            $this->audit->record($purpose === StaffInvitation::INVITE ? 'staff.invitation_issued' : 'staff.password_reset_issued', $by, $staff);
        });

        return $plain;
    }

    /** Cancels open links, e.g. when the account is suspended. */
    public function cancelOpen(Staff $staff): void
    {
        StaffInvitation::query()->where('staff_id', $staff->id)->whereNull('used_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
    }

    /** The admin page that takes the token. The token is in the fragment, so it never reaches a server log or a Referer. */
    public static function url(string $plain, string $purpose): string
    {
        $page = $purpose === StaffInvitation::RESET ? 'reset-password' : 'accept-invite';

        return rtrim((string) config('bhabaghure.admin_url'), '/')."/{$page}#token={$plain}";
    }

    /**
     * Emails the link to the address on file.
     *
     * @return 'sent'|'off'|'failed' `off` when this server's mailer only writes a log, so the email reaches nobody
     */
    public function email(Staff $staff, string $plain, string $purpose): string
    {
        try {
            Mail::to($staff->email)->send(new StaffInvitationMail($staff->name, self::url($plain, $purpose), $purpose, $staff->locale ?: 'bn'));
        } catch (Throwable $e) {
            Log::warning('Staff invitation email failed', ['staff_id' => $staff->id, 'purpose' => $purpose, 'error' => $e->getMessage()]);

            return 'failed';
        }

        return in_array(config('mail.default'), ['log', 'array'], true) ? 'off' : 'sent';
    }

    public function find(string $plain): ?StaffInvitation
    {
        return StaffInvitation::query()->open()->where('token_hash', self::hash($plain))->with('staff')->first();
    }

    /**
     * Sets the password from a valid link, activates an invited account and ends every other session.
     *
     * @throws HrRefused
     */
    public function accept(string $plain, string $password): Staff
    {
        return DB::transaction(function () use ($plain, $password) {
            $invitation = StaffInvitation::query()->open()->where('token_hash', self::hash($plain))->lockForUpdate()->first();
            if ($invitation === null) {
                throw new HrRefused('link_invalid');
            }
            $staff = Staff::query()->whereKey($invitation->staff_id)->lockForUpdate()->firstOrFail();
            if (! $staff->canSignIn()) {
                throw new HrRefused('account_suspended');
            }

            $staff->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'status' => StaffStatus::Active,
            ])->save();
            $invitation->forceFill(['used_at' => now()])->save();
            $this->cancelOpen($staff);
            $this->tokens->revokeAllFor('staff', $staff->id);
            $this->audit->record($invitation->purpose === StaffInvitation::INVITE ? 'staff.invitation_accepted' : 'staff.password_reset', $staff, $staff);

            return $staff;
        });
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
