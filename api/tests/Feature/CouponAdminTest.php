<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Coupon;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCoupons;
use Tests\TestCase;

/** docs/coupons.md §2.6: Admin → Marketing → Coupons — the form's rules, the list, and who may do what. */
class CouponAdminTest extends TestCase
{
    use CreatesCoupons, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    /** @return array<string, mixed> the form as the admin screen sends it: times in Dhaka */
    private function form(array $overrides = []): array
    {
        return [
            'code' => 'eid-2026', 'name' => 'Eid 2026', 'kind' => 'public', 'channel' => 'facebook',
            'discount_type' => 'percent', 'discount_value' => 10, 'max_discount_amount' => 5000, 'min_booking_amount' => 20000,
            'starts_at' => '2026-01-01T00:00', 'ends_at' => '2099-12-31T23:59',
            'usage_limit' => 100, 'per_customer_limit' => 1, 'applies_to' => 'all', 'package_ids' => [], 'is_active' => true, 'notes' => 'Facebook boost',
            ...$overrides,
        ];
    }

    #[Test]
    public function an_admin_creates_a_coupon_its_code_in_capitals_and_its_window_in_dhaka_time(): void
    {
        $admin = $this->staff('admin');

        $this->actingAsApi($admin)->postJson('/api/v1/admin/coupons', $this->form())->assertCreated()
            ->assertJsonPath('data.code', 'EID-2026')->assertJsonPath('data.status', 'active')->assertJsonPath('data.channel', 'facebook')
            ->assertJsonPath('data.discount_value', 10)->assertJsonPath('data.max_discount_amount', 5000)->assertJsonPath('data.min_booking_amount', 20000)
            ->assertJsonPath('data.usage', ['live' => 0, 'used' => 0, 'pending' => 0, 'released' => 0, 'discount_given' => 0, 'revenue' => 0])
            ->assertJsonPath('data.ever_used', false)->assertJsonPath('data.created_by', $admin->name);

        // 10:00 in Dhaka is 04:00 UTC; a fixed discount has no cap; limited to one package.
        $mustang = DB::table('tour_packages')->where('slug', self::MUSTANG)->value('id');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/coupons', $this->form([
            'code' => 'NEWYEAR', 'discount_type' => 'fixed', 'discount_value' => 1500, 'starts_at' => '2030-01-01T10:00', 'ends_at' => '2030-01-31T23:59',
            'applies_to' => 'packages', 'package_ids' => [$mustang],
        ]))->assertCreated()
            ->assertJsonPath('data.starts_at', '2030-01-01T04:00:00+00:00')->assertJsonPath('data.ends_at', '2030-01-31T17:59:00+00:00')
            ->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.max_discount_amount', null)->assertJsonPath('data.packages.0.id', $mustang);

        $this->assertSame(['coupon.created', 'coupon.created'], DB::table('audit_logs')->where('action', 'like', 'coupon.%')->pluck('action')->all());
    }

    #[Test]
    public function the_form_is_checked(): void
    {
        $post = fn (array $overrides) => $this->actingAsApi($this->staff('admin'))->postJson('/api/v1/admin/coupons', $this->form($overrides));

        $post(['code' => 'no', 'discount_value' => 101, 'channel' => 'tv'])->assertUnprocessable()->assertJsonValidationErrors(['code', 'discount_value', 'channel']);
        $post(['code' => 'bad code!'])->assertJsonValidationErrors(['code' => 'Use letters, digits and dashes, 3 to 30 long.']);
        $post(['discount_type' => 'fixed', 'discount_value' => 99.5])->assertJsonValidationErrors(['discount_value']);
        $post(['discount_value' => 0])->assertJsonValidationErrors(['discount_value']);
        $post(['starts_at' => '2026-12-01T00:00', 'ends_at' => '2026-11-01T00:00'])->assertJsonValidationErrors(['ends_at' => 'The coupon must end after it starts.']);
        $post(['usage_limit' => 0, 'per_customer_limit' => -1, 'min_booking_amount' => -5])->assertJsonValidationErrors(['usage_limit', 'per_customer_limit', 'min_booking_amount']);
        $post(['applies_to' => 'packages'])->assertJsonValidationErrors(['package_ids']);
        $post(['kind' => 'passport'])->assertJsonValidationErrors(['passport_number' => 'A passport coupon needs the holder\'s passport number.']);
        $post(['kind' => 'passport', 'passport_number' => '12345'])->assertJsonValidationErrors(['passport_number']);

        $this->assertSame(0, Coupon::withTrashed()->count());
    }

    #[Test]
    public function a_code_belongs_to_one_coupon_ever_archived_or_not(): void
    {
        $admin = $this->staff('admin');
        $this->coupon(['code' => 'EID-2026']);
        $this->coupon(['code' => 'OLD-OFFER'])->delete();

        $this->actingAsApi($admin)->postJson('/api/v1/admin/coupons', $this->form(['code' => 'eid-2026']))->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->actingAsApi($admin)->postJson('/api/v1/admin/coupons', $this->form(['code' => 'old-offer']))->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'This code is taken — by an archived coupon if it isn\'t in the list. Choose another code.']);
        $this->assertSame(2, Coupon::withTrashed()->count());
    }

    #[Test]
    public function a_passport_coupons_passport_is_shown_in_full_only_to_those_who_manage_coupons_and_never_logged(): void
    {
        $admin = $this->staff('admin');
        $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/coupons', $this->form([
            'code' => 'PASSPORT500', 'kind' => 'passport', 'passport_number' => 'b1234 5678', 'holder_name' => 'Nusrat Jahan',
            'discount_type' => 'fixed', 'discount_value' => 500, 'usage_limit' => 1,
        ]))->assertCreated()
            ->assertJsonPath('data.passport_number', 'B12345678')->assertJsonPath('data.passport_masked', 'B1•••••78')->assertJsonPath('data.holder_name', 'Nusrat Jahan')
            ->json('data.id');

        $this->actingAsApi($this->staff('accountant'))->getJson("/api/v1/admin/coupons/{$id}")->assertOk()
            ->assertJsonPath('data.passport_number', null)->assertJsonPath('data.passport_masked', 'B1•••••78');

        // Encrypted at rest and matched by its HMAC, like a traveller's passport; the audit log has field names only.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/coupons/{$id}", $this->form(['code' => 'PASSPORT500', 'kind' => 'passport', 'passport_number' => 'B87654321', 'discount_type' => 'fixed', 'discount_value' => 500]))
            ->assertOk()->assertJsonPath('data.passport_masked', 'B8•••••21');
        $row = DB::table('coupons')->where('id', $id)->first();
        $this->assertStringNotContainsString('B87654321', $row->passport_number);
        $this->assertSame(BookingTraveller::passportHash('B87654321'), $row->passport_number_hash);
        $logged = DB::table('audit_logs')->where('action', 'like', 'coupon.%')->pluck('changes')->implode(' ');
        $this->assertStringContainsString('passport_number', $logged);
        $this->assertStringNotContainsString('1234', $logged);
        $this->assertStringNotContainsString('7654', $logged);

        // A public coupon keeps no passport.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/coupons/{$id}", $this->form(['code' => 'PASSPORT500', 'kind' => 'public', 'passport_number' => 'B87654321']))
            ->assertOk()->assertJsonPath('data.passport_masked', null)->assertJsonPath('data.holder_name', null);
        $this->assertNull(DB::table('coupons')->where('id', $id)->value('passport_number_hash'));
    }

    #[Test]
    public function the_list_filters_by_status_and_its_chips_count_the_same_rows(): void
    {
        $this->coupon(['code' => 'ACTIVE1']);
        $this->coupon(['code' => 'SOON1', 'starts_at' => now()->addDays(5)]);
        $this->coupon(['code' => 'PAST1', 'ends_at' => now()->subDay()]);
        $this->coupon(['code' => 'OFF1', 'is_active' => false]);
        $this->coupon(['code' => 'ONCE1', 'usage_limit' => 1]);
        $this->coupon(['code' => 'GONE1'])->delete();
        $this->book(['coupon_code' => 'ONCE1'])->assertCreated();
        $admin = $this->staff('admin');

        $this->actingAsApi($admin)->getJson('/api/v1/admin/coupons')->assertOk()
            ->assertJsonPath('meta.status_counts', ['all' => 5, 'archived' => 1, 'inactive' => 1, 'expired' => 1, 'scheduled' => 1, 'used_up' => 1, 'active' => 1])
            ->assertJsonCount(5, 'data');
        foreach (['active' => 'ACTIVE1', 'scheduled' => 'SOON1', 'expired' => 'PAST1', 'inactive' => 'OFF1', 'used_up' => 'ONCE1', 'archived' => 'GONE1'] as $status => $code) {
            $this->actingAsApi($admin)->getJson("/api/v1/admin/coupons?status={$status}")->assertOk()
                ->assertJsonPath('data.*.code', [$code])->assertJsonPath('data.0.status', $status);
        }

        $this->actingAsApi($admin)->getJson('/api/v1/admin/coupons?search=once')->assertOk()
            ->assertJsonPath('data.*.code', ['ONCE1'])->assertJsonPath('meta.status_counts.all', 1)
            ->assertJsonPath('data.0.usage', ['live' => 1, 'used' => 0, 'pending' => 1, 'released' => 0, 'discount_given' => 0, 'revenue' => 0])
            ->assertJsonPath('data.0.ever_used', true);
    }

    #[Test]
    public function an_edit_reaches_later_bookings_only_and_a_used_coupon_keeps_its_code(): void
    {
        $admin = $this->staff('admin');
        $coupon = $this->coupon(['code' => 'TRAVEL10']);
        $url = "/api/v1/admin/coupons/{$coupon->id}";

        // Unused, its code can still change.
        $this->actingAsApi($admin)->putJson($url, $this->form(['code' => 'travel-10']))->assertOk()->assertJsonPath('data.code', 'TRAVEL-10');
        // 10% of 1,50,000 capped at 5,000.
        $booking = $this->bookingFrom($this->book(['coupon_code' => 'TRAVEL-10'])->assertJsonPath('data.discount', 5000));

        $this->actingAsApi($admin)->putJson($url, $this->form(['code' => 'TRAVEL-11']))->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'This coupon has been used, so it keeps its code (it is on bookings and invoices). Make a new coupon for a new code.']);
        $this->actingAsApi($admin)->putJson($url, $this->form(['code' => 'TRAVEL-10', 'discount_value' => 20, 'max_discount_amount' => 8000]))->assertOk()
            ->assertJsonPath('data.discount_value', 20)->assertJsonPath('data.max_discount_amount', 8000);

        $this->assertSame('5000.00', $booking->fresh()->discount_amount);
        $this->assertEquals(['code' => 'TRAVEL-10', 'fields' => ['discount_value', 'max_discount_amount']],
            json_decode((string) DB::table('audit_logs')->where('action', 'coupon.updated')->latest('id')->value('changes'), true));
    }

    #[Test]
    public function switching_off_deleting_archiving_and_restoring(): void
    {
        $admin = $this->staff('admin');
        $unused = $this->coupon(['code' => 'UNUSED']);
        $used = $this->coupon(['code' => 'USED1']);
        $booking = $this->bookingFrom($this->book(['coupon_code' => 'USED1']));

        $this->actingAsApi($admin)->postJson("/api/v1/admin/coupons/{$used->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->checkCoupon('USED1', ['phone' => '01811000002'])->assertJsonPath('data.reason', 'inactive');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/coupons/{$used->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');

        // Never used: gone, and its code free again. Used: archived — kept for the booking that names it.
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/coupons/{$unused->id}")->assertOk()->assertJsonPath('data.result', 'deleted');
        $this->assertNull(Coupon::withTrashed()->find($unused->id));
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/coupons/{$used->id}")->assertOk()->assertJsonPath('data.result', 'archived');
        $this->assertTrue(Coupon::withTrashed()->findOrFail($used->id)->trashed());
        $this->checkCoupon('USED1', ['phone' => '01811000002'])->assertJsonPath('data.reason', 'not_found');
        $this->actingAsApi($admin)->getJson("/api/v1/admin/bookings/{$booking->id}")->assertJsonPath('data.coupon.code', 'USED1');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/coupons/{$used->id}", $this->form(['code' => 'USED1']))->assertNotFound();

        $this->actingAsApi($admin)->postJson("/api/v1/admin/coupons/{$used->id}/restore")->assertOk()->assertJsonPath('data.status', 'active');
        $this->checkCoupon('USED1', ['phone' => '01811000002'])->assertJsonPath('data.valid', true);

        $this->assertSame(['coupon.deactivated', 'coupon.activated', 'coupon.deleted', 'coupon.archived', 'coupon.restored'],
            DB::table('audit_logs')->whereIn('action', ['coupon.deactivated', 'coupon.activated', 'coupon.deleted', 'coupon.archived', 'coupon.restored'])->orderBy('id')->pluck('action')->all());
        $this->assertSame(1, Booking::query()->count());
    }

    #[Test]
    public function only_those_given_coupons_see_or_change_them(): void
    {
        $coupon = $this->coupon();
        $writes = [
            ['post', '/api/v1/admin/coupons', $this->form(['code' => 'NEW1'])],
            ['put', "/api/v1/admin/coupons/{$coupon->id}", $this->form(['code' => 'TRAVEL10'])],
            ['post', "/api/v1/admin/coupons/{$coupon->id}/deactivate", []],
            ['post', "/api/v1/admin/coupons/{$coupon->id}/activate", []],
            ['delete', "/api/v1/admin/coupons/{$coupon->id}", []],
            ['post', "/api/v1/admin/coupons/{$coupon->id}/restore", []],
        ];
        $reads = ['/api/v1/admin/coupons', '/api/v1/admin/coupons/options', "/api/v1/admin/coupons/{$coupon->id}", '/api/v1/admin/coupon-report'];

        // Accountants see coupons and the report (decided 2026-09-24) and change nothing.
        $accountant = $this->staff('accountant');
        foreach ($reads as $url) {
            $this->actingAsApi($accountant)->getJson($url)->assertOk();
        }
        foreach ($writes as [$method, $url, $body]) {
            $this->actingAsApi($accountant)->json($method, $url, $body)->assertForbidden();
        }
        // Sales agents and tour operators have neither; nobody signed out has anything.
        foreach (['sales_agent', 'tour_operator'] as $role) {
            $staff = $this->staff($role);
            foreach ($reads as $url) {
                $this->actingAsApi($staff)->getJson($url)->assertForbidden();
            }
            $this->actingAsApi($staff)->postJson('/api/v1/admin/coupons', $this->form(['code' => 'NEW1']))->assertForbidden();
        }
        $this->resetAuthState();
        $this->flushHeaders()->getJson('/api/v1/admin/coupons')->assertUnauthorized();

        $this->assertSame(['TRAVEL10'], Coupon::withTrashed()->pluck('code')->all());
        $this->assertTrue(Coupon::query()->firstOrFail()->is_active);

        // The form's options: packages and channels.
        $this->actingAsApi($this->staff('admin'))->getJson('/api/v1/admin/coupons/options')->assertOk()
            ->assertJsonPath('data.channels', Coupon::CHANNELS)
            ->assertJsonFragment(['id' => DB::table('tour_packages')->where('slug', self::MUSTANG)->value('id'), 'published' => true]);
    }
}
