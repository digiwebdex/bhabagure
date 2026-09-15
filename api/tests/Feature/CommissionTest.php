<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BonusAccount;
use App\Models\BonusTransaction;
use App\Models\BonusWithdrawal;
use App\Models\Booking;
use App\Models\Staff;
use App\Services\Bonus\CommissionDesk;
use App\Services\Booking\BookingStateMachine;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceRecords;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §12 step 4: commission credited to the owner's bonus ledger when a booking is
 * confirmed (3 % of the sale before VAT), reversed when it is cancelled, moved when it is reassigned, and the month-end
 * volume bonus (+0.5 % of the month's sale at 10 bookings) posted once.
 */
class CommissionTest extends TestCase
{
    use CreatesFinanceRecords;
    use RefreshDatabase;

    private Staff $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->staff('admin');
    }

    #[Test]
    public function a_confirmed_booking_credits_its_owner_three_percent_of_the_sale_before_vat_and_cancelling_reverses_it(): void
    {
        $agent = $this->staff('sales_agent');
        // ৳ 1,53,000 with ৳ 3,000 VAT: 3 % of ৳ 1,50,000.
        $booking = $this->confirmed($agent, 153000);

        $entry = BonusTransaction::query()->where('kind', 'commission')->sole();
        $this->assertSame(['credit', '4500.00', $booking->id, null], [$entry->direction, $entry->amount, $entry->booking_id, $entry->created_by_staff_id]);
        $this->assertEquals(['type' => 'tour', 'rate' => 3.0, 'base' => 150000.0, 'booking' => $booking->reference], $entry->rule);
        $this->assertSame($agent->id, $entry->account->staff_id);
        $this->assertTrue(AuditLog::query()->where('action', 'bonus.commission_credited')->where('changes->booking', $booking->reference)->exists());

        // Running it again, or a late event, changes nothing.
        app(CommissionDesk::class)->sync($booking);
        app(CommissionDesk::class)->sync($booking);
        $this->assertSame(1, BonusTransaction::query()->count());

        $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/commission')->assertOk()
            ->assertJsonPath('data.balance', 4500)->assertJsonPath('data.commission.this_month', 4500)
            ->assertJsonPath('data.volume.count', 1)->assertJsonPath('data.volume.base', 150000)
            ->assertJsonPath('data.rules.earns', true)->assertJsonPath('data.rules.rates.tour', 3)->assertJsonPath('data.rules.volume_threshold', 10)
            ->assertJsonPath('data.entries.0.kind', 'commission')->assertJsonPath('data.entries.0.rule.base', 150000)
            ->assertJsonPath('data.entries.0.booking.reference', $booking->reference);

        // Money already asked for doesn't stop the reversal: the balance can't cover the request any more.
        $this->actingAsApi($agent)->postJson('/api/v1/admin/profile/commission/withdrawals', ['amount' => 4000])->assertCreated();
        app(BookingStateMachine::class)->cancel($booking, 'Customer changed plans', $this->admin);

        $reversal = BonusTransaction::query()->where('kind', 'reversal')->sole();
        $this->assertSame(['debit', '4500.00', $entry->id, null], [$reversal->direction, $reversal->amount, $reversal->reverses_id, $reversal->created_by_staff_id]);
        $this->assertSame('booking_cancelled', $reversal->rule['cause']);
        $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/commission')->assertOk()
            ->assertJsonPath('data.balance', 0)->assertJsonPath('data.available', -4000)->assertJsonPath('data.commission.this_month', 0)
            ->assertJsonPath('data.volume.count', 0)->assertJsonPath('data.entries.1.reversed', true);
        $this->actingAsApi($this->admin)->postJson('/api/v1/admin/bonus-withdrawals/'.BonusWithdrawal::query()->sole()->id.'/approve')
            ->assertConflict()->assertJsonPath('code', 'over_available');
        $this->assertTrue(AuditLog::query()->where('action', 'bonus.commission_reversed')->where('changes->cause', 'booking_cancelled')->exists());
    }

    #[Test]
    public function the_commission_follows_the_owner_through_a_claim_and_reassignments(): void
    {
        $agent = $this->staff('sales_agent', ['name' => 'Nabila Karim']);
        $operator = $this->staff('tour_operator', ['name' => 'Tanvir Operator']);

        // The usual way: an agent claims a website inquiry, and the confirmation credits them.
        $claimed = $this->payable(51000);
        $this->actingAsApi($agent)->postJson("/api/v1/admin/bookings/{$claimed->id}/claim")->assertOk();
        $this->assertSame(0, BonusTransaction::query()->count(), 'nothing until it is confirmed');
        app(BookingStateMachine::class)->confirm($claimed->fresh(), $agent);
        $this->assertSame([[$agent->id, 'credit', '1530.00']], $this->ledger($claimed));

        // Paid online and confirmed with nobody owning it: nothing to credit until an admin gives it an owner.
        $booking = $this->confirmed(null, 102000);
        $this->assertSame(0, BonusTransaction::query()->where('booking_id', $booking->id)->count());
        $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $agent->id, 'reason' => 'Nabila took the call'])->assertOk();
        $this->assertSame([[$agent->id, 'credit', '3060.00']], $this->ledger($booking));

        $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $operator->id, 'reason' => 'Nabila is on leave'])->assertOk();
        $this->assertSame([[$agent->id, 'credit', '3060.00'], [$agent->id, 'debit', '3060.00'], [$operator->id, 'credit', '3060.00']], $this->ledger($booking));
        $this->assertEquals(['cause' => 'reassigned', 'booking' => $booking->reference, 'to' => 'Tanvir Operator'], BonusTransaction::query()->where('kind', 'reversal')->sole()->rule);

        // To someone who doesn't earn commission (an admin), then back to the pool: nobody holds it.
        $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $this->admin->id, 'reason' => 'Handling it myself'])->assertOk();
        $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => null, 'reason' => 'Back to the pool'])->assertOk();
        $this->assertSame(0.0, $this->balance($operator));
        $this->assertSame(4, BonusTransaction::query()->where('booking_id', $booking->id)->count());

        // A system reversal doesn't stand in the way of earning it again.
        $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $agent->id, 'reason' => 'Back from leave'])->assertOk();
        $this->assertSame(1530.0 + 3060.0, $this->balance($agent));
        $this->assertSame(1, BonusTransaction::query()->where('booking_id', $booking->id)->where('kind', 'commission')->whereDoesntHave('reversedBy')->count());
    }

    #[Test]
    public function a_commission_reversed_by_hand_is_withheld_and_admins_and_the_super_admin_earn_none(): void
    {
        $agent = $this->staff('sales_agent');
        $operator = $this->staff('tour_operator');
        $booking = $this->confirmed($agent, 153000);
        $commission = BonusTransaction::query()->where('kind', 'commission')->sole();

        $this->actingAsApi($this->admin)->getJson("/api/v1/admin/staff/{$agent->id}/bonus")->assertOk()->assertJsonPath('data.entries.0.reversible', true);
        $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bonus-entries/{$commission->id}/reverse", ['reason' => 'Sold below the floor price'])->assertOk();

        // Away and back again: still withheld from the agent, though whoever else owns it earns.
        $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $operator->id, 'reason' => 'Swap'])->assertOk();
        $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $agent->id, 'reason' => 'Swap back'])->assertOk();
        $this->assertSame(0.0, $this->balance($agent));
        $this->assertSame(1, BonusTransaction::query()->where('kind', 'commission')->where('bonus_account_id', BonusAccount::query()->where('staff_id', $agent->id)->value('id'))->count());
        $this->assertSame(0.0, $this->balance($operator), 'credited, then reversed when it went back');
        $this->assertSame(2, BonusTransaction::query()->where('kind', 'commission')->count());

        foreach (['admin', 'super_admin'] as $role) {
            $owner = $this->staff($role);
            $this->confirmed($owner, 51000);
            $this->assertSame(0.0, $this->balance($owner), "a {$role} owning a booking earns no commission");
            $this->actingAsApi($owner)->getJson('/api/v1/admin/profile/commission')->assertOk()->assertJsonPath('data.rules.earns', false);
        }
    }

    #[Test]
    public function the_volume_bonus_is_posted_once_at_month_end_for_ten_bookings_still_earning(): void
    {
        $star = $this->staff('sales_agent');
        $close = $this->staff('sales_agent');

        $this->travelTo(CarbonImmutable::parse('2026-08-05 12:00', 'Asia/Dhaka'));
        $starBookings = collect(range(1, 10))->map(fn () => $this->confirmed($star, 153000));
        $cancelled = $this->confirmed($star, 153000);
        app(BookingStateMachine::class)->cancel($cancelled, 'Visa refused', $this->admin);
        foreach (range(1, 9) as $i) {
            $this->confirmed($close, 153000);
        }
        $this->actingAsApi($star)->getJson('/api/v1/admin/profile/commission')->assertOk()->assertJsonPath('data.volume.count', 10);
        $this->artisan('commission:volume-bonus', ['month' => '2026-08'])->expectsOutputToContain("isn't over yet")->assertExitCode(1);

        // 00:30 on the 1st, Dhaka: last month by default.
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:30', 'Asia/Dhaka'));
        $this->artisan('commission:volume-bonus')->expectsOutputToContain('Volume bonus for 2026-08: 1 posted.')->assertExitCode(0);
        $this->artisan('commission:volume-bonus', ['month' => '2026-08'])->expectsOutputToContain('0 posted')->assertExitCode(0);

        $bonus = BonusTransaction::query()->where('kind', 'volume_bonus')->sole();
        // 0.5 % of ten bookings' ৳ 1,50,000 sale; the cancelled one doesn't count.
        $this->assertSame(['credit', '7500.00', '2026-08', null], [$bonus->direction, $bonus->amount, $bonus->period, $bonus->created_by_staff_id]);
        $this->assertSame(BonusAccount::query()->where('staff_id', $star->id)->value('id'), $bonus->bonus_account_id);
        $this->assertEquals(['type' => 'volume', 'month' => '2026-08', 'rate' => 0.5, 'threshold' => 10, 'bookings' => 10, 'base' => 1500000.0], $bonus->rule);
        $this->assertSame(9 * 4500.0, $this->balance($close));
        $this->assertSame(10 * 4500.0 + 7500.0, $this->balance($star));

        // Posted is posted: a cancellation afterwards takes back that booking's commission only.
        app(BookingStateMachine::class)->cancel($starBookings->first(), 'Customer fell ill', $this->admin);
        $this->assertFalse($bonus->fresh()->reversedBy()->exists());
        $this->assertSame(9 * 4500.0 + 7500.0, $this->balance($star));
        $this->actingAsApi($star)->getJson('/api/v1/admin/profile/commission')->assertOk()
            ->assertJsonPath('data.commission.total', 9 * 4500 + 7500)->assertJsonPath('data.entries.0.kind', 'reversal');
        $this->actingAsApi($this->admin)->getJson("/api/v1/admin/staff/{$star->id}/bonus")->assertOk()
            ->assertJsonPath('data.entries.1.kind', 'volume_bonus')->assertJsonPath('data.entries.1.rule.bookings', 10);
    }

    /** A booking with an issued invoice, an advance on the books, owned by $owner, confirmed by the admin. */
    private function confirmed(?Staff $owner, float $total): Booking
    {
        $booking = $this->payable($total);
        Booking::query()->whereKey($booking->id)->update(['assigned_staff_id' => $owner?->id]);

        return app(BookingStateMachine::class)->confirm($booking->fresh(), $this->admin);
    }

    /** An unowned inquiry with an issued invoice and an advance on the books: ready to confirm. */
    private function payable(float $total): Booking
    {
        $booking = $this->booking($total);
        app(InvoiceIssuer::class)->issueForBooking($booking, $this->admin);
        DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 10000, 'cash', 'Advance', null, $this->admin));

        return $booking->fresh();
    }

    /** @return list<array{int, string, string}> [owner id, direction, amount] per entry of one booking, in order */
    private function ledger(Booking $booking): array
    {
        return BonusTransaction::query()->where('booking_id', $booking->id)->with('account')->orderBy('id')->get()
            ->map(fn (BonusTransaction $entry) => [$entry->account->staff_id, $entry->direction, $entry->amount])->all();
    }

    private function balance(Staff $staff): float
    {
        $accountId = BonusAccount::query()->where('staff_id', $staff->id)->value('id');

        return $accountId === null ? 0.0 : (float) BonusTransaction::query()->where('bonus_account_id', $accountId)
            ->selectRaw("coalesce(sum(case when direction = 'credit' then amount else -amount end), 0) as balance")->value('balance');
    }
}
