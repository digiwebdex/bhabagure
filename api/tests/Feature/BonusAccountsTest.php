<?php

namespace Tests\Feature;

use App\Exceptions\LedgerImmutable;
use App\Models\AuditLog;
use App\Models\BonusTransaction;
use App\Models\BonusWithdrawal;
use App\Models\BonusWithdrawalEvent;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceRecords;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §7: bonus accounts with an append-only ledger and reversing entries, manual
 * credits, withdrawals from request to paid (a Staff bonuses cash-out) with every step logged, and My commission showing
 * only the signed-in person's figures (docs/phase-5-admin-core.md §4.8).
 */
class BonusAccountsTest extends TestCase
{
    use CreatesFinanceRecords;
    use RefreshDatabase;

    #[Test]
    public function credits_are_reversed_by_entries_the_other_way_and_nobody_credits_themselves(): void
    {
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent', ['name' => 'Nabila Karim']);

        $ledger = $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$agent->id}/bonus/credits", ['amount' => 5000, 'reason' => 'Mustang group bonus'])
            ->assertCreated()->assertJsonPath('data.balance', 5000)->assertJsonPath('data.entries.0.kind', 'manual');
        $entryId = $ledger->json('data.entries.0.id');
        $this->assertTrue(AuditLog::query()->where('action', 'bonus.credited')->where('changes->reason', 'Mustang group bonus')->exists());

        // Nobody credits themselves, except the super admin; the accountant reads but can't change.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$admin->id}/bonus/credits", ['amount' => 5000, 'reason' => 'Mine'])->assertForbidden()->assertJsonPath('code', 'own_bonus');
        $this->actingAsApi($this->staff('super_admin'))->postJson("/api/v1/admin/staff/{$admin->id}/bonus/credits", ['amount' => 700, 'reason' => 'Good month'])->assertCreated();
        $accountant = $this->staff('accountant');
        $this->actingAsApi($accountant)->getJson("/api/v1/admin/staff/{$agent->id}/bonus")->assertOk()->assertJsonPath('data.balance', 5000)->assertJsonPath('data.actions.credit', false);
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/staff/{$agent->id}/bonus/credits", ['amount' => 1, 'reason' => 'Nope'])->assertForbidden();

        // A reversal is a debit entry; the credit stays, marked reversed, and can't be reversed twice.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bonus-entries/{$entryId}/reverse", ['reason' => 'Wrong person'])->assertOk()
            ->assertJsonPath('data.balance', 0)->assertJsonPath('data.entries.0.kind', 'reversal')->assertJsonPath('data.entries.0.direction', 'debit')
            ->assertJsonPath('data.entries.1.reversed', true);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bonus-entries/{$entryId}/reverse", ['reason' => 'Again'])->assertConflict()->assertJsonPath('code', 'already_reversed');
        $reversal = BonusTransaction::query()->where('reverses_id', $entryId)->sole();
        $this->assertSame(['debit', '5000.00', 'Wrong person'], [$reversal->direction, $reversal->amount, $reversal->reason]);

        // Append-only, however it's attempted.
        $this->expectException(LedgerImmutable::class);
        BonusTransaction::query()->findOrFail($entryId)->update(['amount' => 1]);
    }

    #[Test]
    public function a_withdrawal_goes_from_request_to_paid_as_a_staff_bonuses_cash_out_and_a_reversed_payout_puts_the_money_back(): void
    {
        Storage::fake('local');
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$agent->id}/bonus/credits", ['amount' => 12000, 'reason' => 'September commission'])->assertCreated();

        // At least ৳ 500, and no more than is available; an open request holds its money back.
        $this->actingAsApi($agent)->postJson('/api/v1/admin/profile/commission/withdrawals', ['amount' => 400])->assertConflict()->assertJsonPath('code', 'below_minimum');
        $this->actingAsApi($agent)->postJson('/api/v1/admin/profile/commission/withdrawals', ['amount' => 13000])->assertConflict()->assertJsonPath('code', 'over_available');
        $this->actingAsApi($agent)->postJson('/api/v1/admin/profile/commission/withdrawals', ['amount' => 10000, 'note' => 'For Eid'])->assertCreated()
            ->assertJsonPath('data.balance', 12000)->assertJsonPath('data.available', 2000)->assertJsonPath('data.withdrawals.0.status', 'pending')
            ->assertJsonPath('data.withdrawals.0.cancellable', true);
        $this->actingAsApi($agent)->postJson('/api/v1/admin/profile/commission/withdrawals', ['amount' => 3000])->assertConflict()->assertJsonPath('code', 'over_available');
        $withdrawal = BonusWithdrawal::query()->sole();

        // Nobody decides their own; the admin approves, then pays from bKash with the receipt.
        $this->actingAsApi($admin)->getJson('/api/v1/admin/bonus-withdrawals')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.actions.approve', true);
        $this->actingAsApi($admin)->post("/api/v1/admin/bonus-withdrawals/{$withdrawal->id}/pay", ['method' => 'bkash', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])
            ->assertConflict()->assertJsonPath('code', 'not_approved');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bonus-withdrawals/{$withdrawal->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAsApi($admin)->post("/api/v1/admin/bonus-withdrawals/{$withdrawal->id}/pay", ['method' => 'bkash', 'reference' => 'TRX55A', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.method', 'bkash')->assertJsonPath('data.reference', 'TRX55A');
        $this->actingAsApi($admin)->post("/api/v1/admin/bonus-withdrawals/{$withdrawal->id}/pay", ['method' => 'bkash', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])
            ->assertConflict()->assertJsonPath('code', 'already_paid');

        $cash = Transaction::query()->where('category', 'staff_bonuses')->sole();
        $this->assertSame(['out', '10000.00', 'bkash'], [$cash->direction->value, $cash->amount, $cash->method]);
        $this->assertNotNull($cash->evidence_path);
        $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/commission')->assertOk()->assertJsonPath('data.balance', 2000)->assertJsonPath('data.available', 2000)
            ->assertJsonPath('data.entries.0.kind', 'withdrawal');
        $this->assertSame(['requested', 'approved', 'paid'], BonusWithdrawalEvent::query()->orderBy('id')->pluck('action')->all());

        // Reversing the cash-out in the cash book: the money is back in the account and the request is approved again.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/cash-book/{$cash->id}/reverse", ['reason' => 'Sent to the wrong number'])->assertCreated();
        $this->assertSame('approved', $withdrawal->fresh()->status);
        $this->assertNull($withdrawal->fresh()->cash_transaction_id);
        $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/commission')->assertJsonPath('data.balance', 12000)->assertJsonPath('data.available', 2000)
            ->assertJsonPath('data.entries.0.kind', 'reversal');
        $this->assertSame('payment_reversed', BonusWithdrawalEvent::query()->latest('id')->value('action'));

        $this->actingAsApi($admin)->post("/api/v1/admin/bonus-withdrawals/{$withdrawal->id}/pay", ['method' => 'nagad', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])->assertOk();
        $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/commission')->assertJsonPath('data.balance', 2000);
        $this->assertSame(['out', 'in', 'out'], Transaction::query()->where('category', 'staff_bonuses')->orderBy('id')->get()->map(fn (Transaction $row) => $row->direction->value)->all());
    }

    #[Test]
    public function the_owner_cancels_while_pending_a_rejection_needs_a_reason_and_a_credit_asked_for_cant_be_taken_back(): void
    {
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent');
        $other = $this->staff('tour_operator');
        $credit = $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$agent->id}/bonus/credits", ['amount' => 3000, 'reason' => 'Referral'])->json('data.entries.0.id');

        $this->actingAsApi($agent)->postJson('/api/v1/admin/profile/commission/withdrawals', ['amount' => 1000])->assertCreated();
        $first = BonusWithdrawal::query()->sole();
        $this->actingAsApi($other)->postJson("/api/v1/admin/profile/commission/withdrawals/{$first->id}/cancel")->assertNotFound();
        $this->actingAsApi($agent)->postJson("/api/v1/admin/profile/commission/withdrawals/{$first->id}/cancel")->assertOk()->assertJsonPath('data.withdrawals.0.status', 'cancelled');
        $this->actingAsApi($agent)->postJson("/api/v1/admin/profile/commission/withdrawals/{$first->id}/cancel")->assertConflict()->assertJsonPath('code', 'already_decided');

        $this->actingAsApi($agent)->postJson('/api/v1/admin/profile/commission/withdrawals', ['amount' => 2000])->assertCreated();
        $second = BonusWithdrawal::query()->where('status', 'pending')->sole();
        // ৳ 2,000 of the ৳ 3,000 credit is asked for: reversing it would take back money already spoken for, so it isn't
        // offered, and is refused if tried.
        $this->actingAsApi($admin)->getJson("/api/v1/admin/staff/{$agent->id}/bonus")->assertJsonPath('data.entries.0.reversible', false);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bonus-entries/{$credit}/reverse", ['reason' => 'Mistake'])->assertConflict()->assertJsonPath('code', 'already_withdrawn');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bonus-withdrawals/{$second->id}/reject")->assertUnprocessable();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bonus-withdrawals/{$second->id}/reject", ['note' => 'Ask after the Mustang trip closes'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/commission')->assertJsonPath('data.withdrawals.0.decision_note', 'Ask after the Mustang trip closes')
            ->assertJsonPath('data.available', 3000);
        $this->assertTrue(AuditLog::query()->where('action', 'bonus.withdrawal_rejected')->exists());
    }

    #[Test]
    public function my_commission_shows_only_the_signed_in_persons_figures_and_everyone_elses_answers_403(): void
    {
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent');
        $colleague = $this->staff('sales_agent');
        foreach ([[$agent, now()], [$agent, now('Asia/Dhaka')->startOfMonth()->subDays(3)], [$colleague, now()]] as [$owner, $confirmedAt]) {
            DB::table('bookings')->where('id', $this->booking(120000)->id)->update(['assigned_staff_id' => $owner->id, 'status' => 'confirmed', 'confirmed_at' => $confirmedAt]);
        }
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$colleague->id}/bonus/credits", ['amount' => 9000, 'reason' => 'Their bonus'])->assertCreated();

        $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/commission')->assertOk()
            ->assertJsonPath('data.sales.this_month', ['count' => 1, 'total' => 120000])
            ->assertJsonPath('data.sales.last_month.count', 1)
            ->assertJsonPath('data.balance', 0)->assertJsonPath('data.min_withdrawal', 500);

        // The company balance, other people's commission and bonus, payroll and the withdrawals queue: 403 to a sales agent.
        foreach (['/api/v1/admin/payments/balance', "/api/v1/admin/staff/{$colleague->id}/bonus", '/api/v1/admin/bonus-withdrawals', '/api/v1/admin/payroll', '/api/v1/admin/payments/summary', '/api/v1/admin/cash-book'] as $url) {
            $this->actingAsApi($agent)->getJson($url)->assertForbidden();
        }
        $this->actingAsApi($agent)->postJson("/api/v1/admin/staff/{$colleague->id}/bonus/credits", ['amount' => 1, 'reason' => 'Nope'])->assertForbidden();
        // A tour operator (commission.view_own) has their own page too.
        $this->actingAsApi($this->staff('tour_operator'))->getJson('/api/v1/admin/profile/commission')->assertOk()->assertJsonPath('data.balance', 0);
    }
}
