<?php

namespace Tests\Feature;

use App\Mail\StaffInvitationMail;
use App\Models\AuditLog;
use App\Models\RefreshToken;
use App\Models\Staff;
use App\Models\StaffInvitation;
use App\Services\Hr\HrRefused;
use App\Services\Hr\StaffDirectory;
use App\Services\Hr\StaffInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceRecords;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §4.1: adding staff by invitation (no temporary passwords), the role rules
 * that keep the proprietor in control, suspension ending sessions at once, and reset links only the account's owner sees.
 */
class StaffManagementTest extends TestCase
{
    use CreatesFinanceRecords, RefreshDatabase;

    #[Test]
    public function an_admin_adds_staff_who_set_their_own_password_from_a_one_time_invitation(): void
    {
        Mail::fake();
        $admin = $this->staff('admin');

        $created = $this->actingAsApi($admin)->postJson('/api/v1/admin/staff', [
            'name' => 'Nabila Karim', 'email' => ' Nabila@Example.test ', 'phone' => '01711-223344', 'role' => 'sales_agent',
            'designation' => 'Sales executive', 'joined_on' => '2026-09-01',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.email', 'nabila@example.test')
            ->assertJsonPath('data.phone', '8801711223344')
            ->assertJsonPath('data.role.name', 'sales_agent')
            ->assertJsonPath('data.designation', 'Sales executive')
            ->assertJsonPath('data.employee_code', 'STF-001')
            // The test mailer delivers nowhere, and the admin is told so.
            ->assertJsonPath('invitation.email', 'off');

        $url = (string) $created->json('invitation.url');
        $this->assertStringStartsWith(rtrim((string) config('bhabaghure.admin_url'), '/').'/accept-invite#token=', $url);
        $token = substr($url, strpos($url, '#token=') + 7);
        Mail::assertSent(StaffInvitationMail::class, fn (StaffInvitationMail $mail) => $mail->hasTo('nabila@example.test') && $mail->url === $url);
        $this->assertSame(1, StaffInvitation::query()->count());
        $this->assertNotSame($token, StaffInvitation::query()->value('token_hash'));

        $this->flushHeaders();
        $this->resetAuthState();
        $this->postJson('/api/v1/staff/auth/invitation/check', ['token' => $token])->assertOk()
            ->assertJsonPath('data.name', 'Nabila Karim')->assertJsonPath('data.purpose', 'invite');
        $this->postJson('/api/v1/staff/auth/invitation/accept', ['token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/staff/auth/invitation/accept', ['token' => $token, 'password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'])
            ->assertOk()->assertJsonPath('staff.status', 'active')->assertJsonPath('staff.role', 'sales_agent')->assertJsonStructure(['access_token']);

        // The link works once; the password it set signs in.
        $this->resetAuthState();
        $this->postJson('/api/v1/staff/auth/invitation/accept', ['token' => $token, 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password'])
            ->assertStatus(410)->assertJsonPath('code', 'link_invalid');
        $this->postJson('/api/v1/staff/auth/invitation/check', ['token' => $token])->assertStatus(410);
        $this->postJson('/api/v1/staff/auth/login', ['email' => 'nabila@example.test', 'password' => 'a-long-new-password'])->assertOk();

        $staff = Staff::query()->where('email', 'nabila@example.test')->sole();
        $this->assertTrue(AuditLog::query()->where('action', 'staff.created')->where('actor_id', $admin->id)->where('auditable_id', $staff->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'staff.invitation_accepted')->where('auditable_id', $staff->id)->exists());
    }

    #[Test]
    public function a_new_invitation_cancels_the_old_link_and_an_expired_link_is_refused(): void
    {
        Mail::fake();
        $admin = $this->staff('admin');
        $first = $this->actingAsApi($admin)->postJson('/api/v1/admin/staff', ['name' => 'Imran Hossain', 'email' => 'imran@example.test', 'role' => 'sales_agent'])->assertCreated();
        $staffId = $first->json('data.id');
        $oldToken = self::token($first->json('invitation.url'));

        $second = $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$staffId}/invitation")->assertOk();
        $newToken = self::token($second->json('invitation.url'));

        $this->flushHeaders();
        $this->resetAuthState();
        $this->postJson('/api/v1/staff/auth/invitation/check', ['token' => $oldToken])->assertStatus(410);
        $this->postJson('/api/v1/staff/auth/invitation/check', ['token' => $newToken])->assertOk();

        $this->travel(StaffInvitations::INVITE_HOURS + 1)->hours();
        $this->postJson('/api/v1/staff/auth/invitation/accept', ['token' => $newToken, 'password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'])
            ->assertStatus(410);
        $this->travelBack();

        // Someone who has set a password gets a reset, not another invitation.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$this->staff('sales_agent')->id}/invitation")->assertConflict()->assertJsonPath('code', 'not_invited');
    }

    #[Test]
    public function role_rules_keep_the_proprietor_in_control(): void
    {
        $owner = $this->staff('super_admin');
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent');

        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$agent->id}/role", ['role' => 'super_admin'])->assertForbidden()->assertJsonPath('code', 'super_admin_only');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/staff', ['name' => 'X', 'email' => 'x@example.test', 'role' => 'super_admin'])->assertForbidden();
        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$owner->id}/role", ['role' => 'admin'])->assertForbidden()->assertJsonPath('code', 'super_admin_only');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$owner->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$owner->id}/suspend", ['reason' => 'Not allowed'])->assertForbidden();
        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$admin->id}/role", ['role' => 'sales_agent'])->assertForbidden()->assertJsonPath('code', 'own_role');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$admin->id}/suspend", ['reason' => 'Not allowed'])->assertForbidden()->assertJsonPath('code', 'own_account');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/staff/options')->assertOk()->assertJsonMissing(['name' => 'super_admin']);

        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$agent->id}/role", ['role' => 'accountant'])->assertOk()->assertJsonPath('data.role.name', 'accountant');
        $this->assertTrue(AuditLog::query()->where('action', 'staff.role_changed')->where('auditable_id', $agent->id)->where('changes->from', 'sales_agent')->where('changes->to', 'accountant')->exists());

        // A super admin may make someone else super admin, and then demote them; the last one can never be removed.
        $this->actingAsApi($owner)->putJson("/api/v1/admin/staff/{$admin->id}/role", ['role' => 'super_admin'])->assertOk();
        $this->actingAsApi($owner)->putJson("/api/v1/admin/staff/{$admin->id}/role", ['role' => 'admin'])->assertOk();
        try {
            app(StaffDirectory::class)->changeRole($owner, 'admin', $this->staff('super_admin', ['status' => 'suspended']));
            $this->fail('The last active super admin was demoted.');
        } catch (HrRefused $e) {
            $this->assertSame('last_super_admin', $e->reason);
        }

        // Other roles can't reach the staff screens at all.
        foreach (['sales_agent', 'accountant', 'tour_operator'] as $role) {
            $this->actingAsApi($this->staff($role))->getJson('/api/v1/admin/staff')->assertForbidden();
        }
    }

    #[Test]
    public function suspending_ends_every_session_at_once_and_reactivating_restores_access(): void
    {
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent');
        $access = $this->postJson('/api/v1/staff/auth/login', ['email' => $agent->email, 'password' => 'correct-horse-battery'])->assertOk()->json('access_token');
        $this->assertSame(1, RefreshToken::query()->where('guard', 'staff')->where('subject_id', $agent->id)->whereNull('revoked_at')->count());

        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$agent->id}/suspend", ['reason' => 'Left the company', 'left_on' => '2026-09-30'])
            ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.profile.left_on', '2026-09-30');
        $this->assertSame(0, RefreshToken::query()->where('guard', 'staff')->where('subject_id', $agent->id)->whereNull('revoked_at')->count());

        $this->resetAuthState();
        $this->withToken($access)->getJson('/api/v1/admin/customers')->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->flushHeaders();
        $this->resetAuthState();
        $this->postJson('/api/v1/staff/auth/login', ['email' => $agent->email, 'password' => 'correct-horse-battery'])->assertForbidden();

        // They had signed in before, so they come back active; the leaving date goes.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$agent->id}/reactivate")
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.profile.left_on', null);
        $this->flushHeaders();
        $this->resetAuthState();
        $this->postJson('/api/v1/staff/auth/login', ['email' => $agent->email, 'password' => 'correct-horse-battery'])->assertOk();
    }

    #[Test]
    public function a_password_reset_goes_only_to_the_email_on_file_and_signs_out_everywhere(): void
    {
        Mail::fake();
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent');
        $this->postJson('/api/v1/staff/auth/login', ['email' => $agent->email, 'password' => 'correct-horse-battery'])->assertOk();

        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$admin->id}/password-reset")->assertForbidden()->assertJsonPath('code', 'own_account');
        $response = $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$agent->id}/password-reset")->assertOk()->assertJsonPath('data.email', 'off');
        $this->assertStringNotContainsString('token', $response->getContent());

        $url = null;
        Mail::assertSent(StaffInvitationMail::class, function (StaffInvitationMail $mail) use ($agent, &$url) {
            $url = $mail->url;

            return $mail->hasTo($agent->email) && $mail->purpose === StaffInvitation::RESET && str_contains($mail->url, '/reset-password#token=');
        });

        $this->flushHeaders();
        $this->resetAuthState();
        $this->postJson('/api/v1/staff/auth/invitation/accept', ['token' => self::token($url), 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password'])->assertOk();
        // Only the session the reset just opened is left.
        $this->assertSame(1, RefreshToken::query()->where('guard', 'staff')->where('subject_id', $agent->id)->whereNull('revoked_at')->count());
        $this->resetAuthState();
        $this->postJson('/api/v1/staff/auth/login', ['email' => $agent->email, 'password' => 'correct-horse-battery'])->assertUnauthorized();
        $this->postJson('/api/v1/staff/auth/login', ['email' => $agent->email, 'password' => 'a-brand-new-password'])->assertOk();
    }

    #[Test]
    public function the_hr_record_keeps_private_numbers_encrypted_and_the_audit_log_names_fields_only(): void
    {
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent');
        $other = $this->staff('sales_agent');

        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$agent->id}", [
            'designation' => 'Senior sales executive', 'joined_on' => '2025-02-01', 'date_of_birth' => '1994-11-02',
            'nid_number' => '1994269123456', 'payout_method' => 'bkash', 'payout_account' => '01711223344',
            'emergency_contact_name' => 'Rahim Karim', 'emergency_contact_phone' => '01811223344',
        ])->assertOk()->assertJsonPath('data.profile.nid_number', '1994269123456')->assertJsonPath('data.profile.payout_account', '01711223344');

        $row = DB::table('staff_profiles')->where('staff_id', $agent->id)->first();
        $this->assertStringNotContainsString('1994269123456', (string) $row->nid_number);
        $this->assertStringNotContainsString('01711223344', (string) $row->payout_account);
        $log = AuditLog::query()->where('action', 'staff.updated')->where('auditable_id', $agent->id)->sole();
        $this->assertContains('nid_number', $log->changes['fields']);
        $this->assertStringNotContainsString('1994269123456', json_encode($log->changes));

        // Saving the same values again changes nothing, so nothing is logged.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$agent->id}", ['nid_number' => '1994269123456', 'payout_account' => '01711223344'])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'staff.updated')->where('auditable_id', $agent->id)->count());

        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$other->id}", ['nid_number' => '1994269123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('nid_number');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$other->id}", ['nid_number' => '12345'])->assertUnprocessable();

        // The sign-in email of someone using their account is the super admin's to change.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$agent->id}", ['email' => 'new@example.test'])->assertForbidden()->assertJsonPath('code', 'email_locked');
        $this->actingAsApi($this->staff('super_admin'))->putJson("/api/v1/admin/staff/{$agent->id}", ['email' => 'new@example.test'])->assertOk()->assertJsonPath('data.email', 'new@example.test');

        // Everyone sees their own record, and only their own.
        $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/record')->assertOk()->assertJsonPath('data.designation', 'Senior sales executive');
        $this->actingAsApi($other)->getJson('/api/v1/admin/profile/record')->assertOk()->assertJsonPath('data.nid_number', null);
    }

    #[Test]
    public function the_list_filters_by_status_and_counts_sales_closed_this_month(): void
    {
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent', ['name' => 'Agent Alpha']);
        $this->staff('tour_operator', ['name' => 'Leaver Beta', 'status' => 'suspended']);
        foreach ([now(), now(), now('Asia/Dhaka')->startOfMonth()->subDay()] as $confirmedAt) {
            DB::table('bookings')->where('id', $this->booking()->id)->update(['assigned_staff_id' => $agent->id, 'status' => 'confirmed', 'confirmed_at' => $confirmedAt]);
        }

        $list = $this->actingAsApi($admin)->getJson('/api/v1/admin/staff?search=alpha')->assertOk();
        $this->assertSame(1, $list->json('meta.total'));
        $this->assertSame(2, $list->json('data.0.closed_sales_month'));
        $this->assertSame(0, $list->json('data.0.documents_attention'));

        $this->assertSame(0, $this->actingAsApi($admin)->getJson('/api/v1/admin/staff?search=beta')->json('meta.total'));
        $this->assertSame(1, $this->actingAsApi($admin)->getJson('/api/v1/admin/staff?status=suspended')->json('meta.total'));
        $this->assertSame(1, $this->actingAsApi($admin)->getJson('/api/v1/admin/staff?role=tour_operator&status=all')->json('meta.total'));
    }

    private static function token(string $url): string
    {
        return substr($url, strpos($url, '#token=') + 7);
    }
}
