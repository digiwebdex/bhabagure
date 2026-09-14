<?php

namespace App\Services\Hr;

use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Models\StaffInvitation;
use App\Models\StaffProfile;
use App\Services\AuditLogger;
use App\Services\Auth\RefreshTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Staff records and their roles (docs/phase-7-hr-attendance-bonus-wallet.md §4.1). The rules that keep the proprietor in
 * control live here, not in the screens: nobody changes their own role or suspends themselves; only a super admin
 * touches a super admin; the last active super admin can't be demoted or suspended. Every change is audited.
 */
final class StaffDirectory
{
    public const PROFILE_FIELDS = [
        'designation', 'joined_on', 'left_on', 'date_of_birth', 'nid_number', 'address',
        'emergency_contact_name', 'emergency_contact_phone', 'payout_method', 'payout_account',
    ];

    /** Encrypted fields. The audit log only ever names changed fields, never their values. */
    private const PRIVATE_FIELDS = ['nid_number', 'payout_account'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StaffInvitations $invitations,
        private readonly RefreshTokens $tokens,
    ) {}

    /**
     * A new staff member, invited to set their own password.
     *
     * @param  array{name: string, email: string, phone?: ?string, role: string, locale?: ?string, designation?: ?string, joined_on?: ?string}  $data
     * @return array{0: Staff, 1: string} the staff member and the invitation's plain token
     *
     * @throws HrRefused
     */
    public function create(array $data, Staff $by): array
    {
        $this->ensureAssignable($data['role'], $by);

        return DB::transaction(function () use ($data, $by) {
            $staff = Staff::query()->create([
                'employee_code' => self::nextEmployeeCode(),
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'phone' => $data['phone'] ?? null,
                // Nobody knows this password: the invitation sets the real one.
                'password' => Str::password(64),
                'status' => StaffStatus::Invited,
                'must_change_password' => false,
                'locale' => $data['locale'] ?? 'bn',
            ]);
            $staff->syncRoles([$data['role']]);
            StaffProfile::query()->create([
                'staff_id' => $staff->id,
                'designation' => $data['designation'] ?? null,
                'joined_on' => $data['joined_on'] ?? null,
                'updated_by_staff_id' => $by->id,
            ]);
            $this->audit->record('staff.created', $by, $staff, ['role' => $data['role']]);

            return [$staff, $this->invitations->issue($staff, StaffInvitation::INVITE, $by)];
        });
    }

    /**
     * @param  array<string, mixed>  $data  name, email, phone, locale and the profile fields; absent keys are left alone
     *
     * @throws HrRefused
     */
    public function update(Staff $staff, array $data, Staff $by): Staff
    {
        $this->ensureMayManage($staff, $by);

        if (array_key_exists('email', $data) && Str::lower((string) $data['email']) !== $staff->email
            && $staff->status !== StaffStatus::Invited && ! $by->isSuperAdmin()) {
            throw new HrRefused('email_locked');
        }
        if (filled($data['nid_number'] ?? null) && StaffProfile::query()->where('nid_number_hash', StaffDocument::numberHash((string) $data['nid_number']))
            ->where('staff_id', '!=', $staff->id)->exists()) {
            throw new HrRefused('nid_on_another_record');
        }

        return DB::transaction(function () use ($staff, $data, $by) {
            $account = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'locale']));
            if (isset($account['email'])) {
                $account['email'] = Str::lower((string) $account['email']);
            }
            $staff->fill($account);
            $changed = array_keys($staff->getDirty());
            $staff->save();

            $profile = $staff->profile()->firstOrNew();
            $fields = array_intersect_key($data, array_flip(self::PROFILE_FIELDS));
            // An encrypted value re-encrypts on every assignment, so it would always look changed: compare the plain values.
            foreach (self::PRIVATE_FIELDS as $field) {
                if (array_key_exists($field, $fields) && $fields[$field] === $profile->{$field}) {
                    unset($fields[$field]);
                }
            }
            $profile->fill($fields + ['updated_by_staff_id' => $by->id]);
            $changed = [...$changed, ...array_values(array_diff(array_keys($profile->getDirty()), ['updated_by_staff_id', 'nid_number_hash']))];
            $profile->save();

            if ($changed !== []) {
                $this->audit->record('staff.updated', $by, $staff, ['fields' => array_values(array_unique($changed))]);
            }

            return $staff->refresh();
        });
    }

    /** @throws HrRefused */
    public function changeRole(Staff $staff, string $role, Staff $by): void
    {
        if ($staff->is($by)) {
            throw new HrRefused('own_role');
        }
        $this->ensureMayManage($staff, $by);
        $this->ensureAssignable($role, $by);
        $from = $staff->getRoleNames()->first();
        if ($from === $role) {
            return;
        }
        if ($from === StaffRole::SuperAdmin->value) {
            $this->ensureAnotherSuperAdmin($staff);
        }

        DB::transaction(function () use ($staff, $role, $by, $from) {
            $staff->syncRoles([$role]);
            $this->audit->record('staff.role_changed', $by, $staff, ['from' => $from, 'to' => $role]);
        });
    }

    /** Ends every session at once. A leaver is suspended with their leaving date. @throws HrRefused */
    public function suspend(Staff $staff, string $reason, ?string $leftOn, Staff $by): void
    {
        if ($staff->is($by)) {
            throw new HrRefused('own_account');
        }
        $this->ensureMayManage($staff, $by);
        if ($staff->status === StaffStatus::Suspended) {
            throw new HrRefused('already_suspended');
        }
        if ($staff->isSuperAdmin()) {
            $this->ensureAnotherSuperAdmin($staff);
        }

        DB::transaction(function () use ($staff, $reason, $leftOn, $by) {
            $staff->forceFill(['status' => StaffStatus::Suspended])->save();
            if ($leftOn !== null) {
                $staff->profile()->firstOrNew()->fill(['left_on' => $leftOn, 'updated_by_staff_id' => $by->id])->save();
            }
            $this->tokens->revokeAllFor('staff', $staff->id);
            $this->invitations->cancelOpen($staff);
            $this->audit->record('staff.suspended', $by, $staff, array_filter(['reason' => $reason, 'left_on' => $leftOn]));
        });
    }

    /** Back to active, or to invited if they never set a password; a rehired leaver loses the leaving date. @throws HrRefused */
    public function reactivate(Staff $staff, Staff $by): void
    {
        $this->ensureMayManage($staff, $by);
        if ($staff->status !== StaffStatus::Suspended) {
            throw new HrRefused('not_suspended');
        }

        DB::transaction(function () use ($staff, $by) {
            $hasPassword = $staff->last_login_at !== null || $staff->invitations()->whereNotNull('used_at')->exists();
            $staff->forceFill(['status' => $hasPassword ? StaffStatus::Active : StaffStatus::Invited])->save();
            $profile = $staff->profile()->first();
            if ($profile?->left_on !== null) {
                $profile->fill(['left_on' => null, 'updated_by_staff_id' => $by->id])->save();
            }
            $this->audit->record('staff.reactivated', $by, $staff);
        });
    }

    /**
     * A new invitation link for someone who hasn't set a password yet.
     *
     * @throws HrRefused
     */
    public function reinvite(Staff $staff, Staff $by): string
    {
        $this->ensureMayManage($staff, $by);
        if ($staff->status !== StaffStatus::Invited) {
            throw new HrRefused('not_invited');
        }

        return $this->invitations->issue($staff, StaffInvitation::INVITE, $by);
    }

    /**
     * A reset link, emailed to the address on file and never shown to whoever asked for it.
     *
     * @return 'sent'|'off'|'failed'
     *
     * @throws HrRefused
     */
    public function sendPasswordReset(Staff $staff, Staff $by): string
    {
        if ($staff->is($by)) {
            throw new HrRefused('own_account');
        }
        $this->ensureMayManage($staff, $by);
        if ($staff->status !== StaffStatus::Active) {
            throw new HrRefused('not_active');
        }

        return $this->invitations->email($staff, $this->invitations->issue($staff, StaffInvitation::RESET, $by), StaffInvitation::RESET);
    }

    /** The roles $by may give someone: every staff role, super admin only from a super admin. */
    public static function assignableRoles(Staff $by): array
    {
        return Role::query()->where('guard_name', 'staff')
            ->when(! $by->isSuperAdmin(), fn ($query) => $query->where('name', '!=', StaffRole::SuperAdmin->value))
            ->orderByDesc('is_system')->orderBy('id')->get()->all();
    }

    public static function nextEmployeeCode(): string
    {
        $next = Staff::withTrashed()->where('employee_code', 'like', 'STF-%')->count() + 1;
        while (Staff::withTrashed()->where('employee_code', $code = sprintf('STF-%03d', $next))->exists()) {
            $next++;
        }

        return $code;
    }

    /** @throws HrRefused */
    private function ensureMayManage(Staff $staff, Staff $by): void
    {
        if ($staff->isSuperAdmin() && ! $by->isSuperAdmin()) {
            throw new HrRefused('super_admin_only');
        }
    }

    /** @throws HrRefused */
    private function ensureAssignable(string $role, Staff $by): void
    {
        if (! collect(self::assignableRoles($by))->contains('name', $role)) {
            throw new HrRefused($role === StaffRole::SuperAdmin->value ? 'super_admin_only' : 'unknown_role');
        }
    }

    /** @throws HrRefused */
    private function ensureAnotherSuperAdmin(Staff $staff): void
    {
        $others = Staff::role(StaffRole::SuperAdmin->value, 'staff')->where('status', StaffStatus::Active->value)->whereKeyNot($staff->id)->exists();
        if (! $others) {
            throw new HrRefused('last_super_admin');
        }
    }
}
