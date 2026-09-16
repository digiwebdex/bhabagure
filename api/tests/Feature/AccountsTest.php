<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Staff;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-9-accounts.md: the Accounts screens — a chart of accounts staff extend themselves, the journal behind
 * every figure with entries staff can post and reverse (never edit or delete), and the two reports that read it.
 */
class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): Staff
    {
        return $this->staff('accountant', ['email' => 'accounts@example.test', 'phone' => '8801711000700']);
    }

    #[Test]
    public function the_chart_of_accounts_lists_every_account_with_its_balance_the_right_way_round(): void
    {
        $this->seed(ContentSeeder::class);
        $this->issueAndPayABooking();

        $chart = $this->actingAsApi($this->accountant())->getJson('/api/v1/admin/accounts')->assertOk()->json();
        $by = collect($chart['data'])->keyBy('code');

        // The booking: ৳ 76,500 invoiced (sales 75,000 + VAT 1,500) and paid in cash.
        $this->assertEquals(76500.0, $by[Account::CASH]['balance'], 'cash is an asset: debits raise it');
        $this->assertEquals(0.0, $by[Account::RECEIVABLE]['balance'], 'the invoice was paid in full');
        $this->assertEquals(75000.0, $by[Account::PACKAGE_SALES]['balance'], 'income is credit-natured, so it reads positive');
        $this->assertEquals(1500.0, $by[Account::VAT_PAYABLE]['balance']);
        $this->assertTrue($by[Account::CASH]['is_system'] && $by[Account::CASH]['is_money']);
        $this->assertSame(['asset', 'liability', 'equity', 'income', 'expense'], $chart['meta']['types']);
        $this->assertEquals(76500.0, $chart['meta']['totals']['asset']);

        // Assets = liabilities + equity + income − expenses: the books balance.
        $totals = $chart['meta']['totals'];
        $this->assertEquals(round($totals['asset'], 2), round($totals['liability'] + $totals['equity'] + $totals['income'] - $totals['expense'], 2));
    }

    #[Test]
    public function staff_add_their_own_accounts_but_never_break_the_ones_the_software_posts_to(): void
    {
        $accountant = $this->accountant();

        // The number comes from the kind's range, and the account appears in the chart.
        $created = $this->actingAsApi($accountant)->postJson('/api/v1/admin/accounts', ['name' => 'Bank charges', 'type' => 'expense', 'description' => 'Monthly account fees'])
            ->assertCreated()->json('data');
        $this->assertSame(['5500', 'expense', false], [$created['code'], $created['type'], $created['is_system']]);
        $this->actingAsApi($accountant)->postJson('/api/v1/admin/accounts', ['name' => 'Air ticket commission', 'type' => 'income'])
            ->assertCreated()->assertJsonPath('data.code', '4500');

        // A number outside the kind's range, and a repeat of one already used, are both refused.
        $this->actingAsApi($accountant)->postJson('/api/v1/admin/accounts', ['name' => 'Wrong range', 'type' => 'expense', 'code' => '1500'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAsApi($accountant)->postJson('/api/v1/admin/accounts', ['name' => 'Taken', 'type' => 'expense', 'code' => '5500'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        // A system account keeps its number and kind; only its wording changes.
        $cash = Account::query()->where('code', Account::CASH)->sole();
        $this->actingAsApi($accountant)->putJson("/api/v1/admin/accounts/{$cash->id}", ['name' => 'Cash in the office', 'type' => 'expense', 'code' => '5900'])->assertOk();
        $cash->refresh();
        $this->assertSame(['Cash in the office', 'asset', Account::CASH], [$cash->name_en, $cash->type, $cash->code]);
        $this->actingAsApi($accountant)->deleteJson("/api/v1/admin/accounts/{$cash->id}")->assertStatus(409)->assertJsonPath('code', 'account_in_use');

        // A staff account with nothing posted to it can go; one with entries cannot.
        $spare = Account::query()->where('code', '4500')->sole();
        $this->actingAsApi($accountant)->deleteJson("/api/v1/admin/accounts/{$spare->id}")->assertOk();
        $this->assertDatabaseMissing('accounts', ['code' => '4500']);

        $used = Account::query()->where('code', '5500')->sole();
        $this->postEntry($accountant, [['account_id' => $used->id, 'debit' => 500], ['account_id' => Account::query()->where('code', Account::OTHER_EXPENSES)->value('id'), 'credit' => 500]]);
        $this->actingAsApi($accountant)->deleteJson("/api/v1/admin/accounts/{$used->id}")->assertStatus(409);

        // Reading needs the permission; adding needs its own.
        $agent = $this->staff('sales_agent', ['email' => 'agent@example.test', 'phone' => '8801711000701']);
        $this->actingAsApi($agent)->getJson('/api/v1/admin/accounts')->assertForbidden();
        $admin = $this->staff('admin', ['email' => 'admin2@example.test', 'phone' => '8801711000702']);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/accounts')->assertOk();
        $this->actingAsApi($admin)->postJson('/api/v1/admin/accounts', ['name' => 'Not mine', 'type' => 'expense'])->assertForbidden();
    }

    #[Test]
    public function a_journal_entry_must_balance_leave_money_alone_and_can_only_be_undone_by_reversing_it(): void
    {
        $accountant = $this->accountant();
        $rent = Account::query()->where('code', Account::OFFICE_RENT)->value('id');
        $payable = Account::query()->where('code', Account::VAT_PAYABLE)->value('id');
        $cash = Account::query()->where('code', Account::CASH)->value('id');

        $post = fn (array $lines, array $extra = []) => $this->actingAsApi($accountant)
            ->postJson('/api/v1/admin/journal-entries', ['entry_date' => now('Asia/Dhaka')->toDateString(), 'description' => 'September rent accrual', 'lines' => $lines] + $extra);

        $post([['account_id' => $rent, 'debit' => 20000], ['account_id' => $payable, 'credit' => 15000]])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $post([['account_id' => $rent, 'debit' => 20000, 'credit' => 5000], ['account_id' => $payable, 'credit' => 15000]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.debit');
        $post([['account_id' => $cash, 'debit' => 20000], ['account_id' => $payable, 'credit' => 20000]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        $post([['account_id' => $rent, 'debit' => 20000]])->assertUnprocessable();

        $id = $post([['account_id' => $rent, 'debit' => 20000], ['account_id' => $payable, 'credit' => 20000]])->assertCreated()->json('data.id');
        $entry = JournalEntry::query()->with('lines')->findOrFail($id);
        $this->assertNull($entry->source_type, 'a staff entry belongs to no document');
        $this->assertSame([20000.0, 20000.0], [(float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit')]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'journal.posted', 'auditable_id' => $id]);

        // It shows in the journal as a staff entry, and can be reversed exactly once.
        $row = collect($this->actingAsApi($accountant)->getJson('/api/v1/admin/journal-entries?kind=manual')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertEquals([true, true, 20000.0], [$row['manual'], $row['actions']['reverse'], $row['total']]);

        $this->actingAsApi($accountant)->postJson("/api/v1/admin/journal-entries/{$id}/reverse", ['reason' => 'Booked twice'])->assertCreated();
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/journal-entries/{$id}/reverse", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'not_reversible');
        $this->assertEquals(0.0, collect($this->actingAsApi($accountant)->getJson('/api/v1/admin/accounts')->json('data'))->firstWhere('code', Account::OFFICE_RENT)['balance']);

        // The software's own entries are corrected where they were made, not here.
        $this->seed(ContentSeeder::class);
        $booking = $this->issueAndPayABooking();
        $fromBooking = JournalEntry::query()->whereNotNull('source_type')->where('booking_id', $booking->id)->firstOrFail();
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/journal-entries/{$fromBooking->id}/reverse", ['reason' => 'Not allowed here'])->assertStatus(409);

        // Only the accountant posts entries; an admin may look.
        $admin = $this->staff('admin', ['email' => 'admin3@example.test', 'phone' => '8801711000703']);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/journal-entries')->assertOk();
        $this->actingAsApi($admin)->postJson('/api/v1/admin/journal-entries', ['entry_date' => now('Asia/Dhaka')->toDateString(), 'description' => 'Nope', 'lines' => [['account_id' => $rent, 'debit' => 1], ['account_id' => $payable, 'credit' => 1]]])->assertForbidden();
    }

    #[Test]
    public function the_reports_read_the_journal_so_they_agree_with_the_chart_of_accounts(): void
    {
        $this->seed(ContentSeeder::class);
        $this->issueAndPayABooking();
        $accountant = $this->accountant();
        $cash = Account::query()->where('code', Account::CASH)->sole();

        $report = $this->actingAsApi($accountant)->getJson("/api/v1/admin/reports/account-transactions?account_id={$cash->id}")->assertOk()->json();
        $this->assertEquals(0.0, $report['meta']['opening']);
        $this->assertEquals(76500.0, $report['meta']['closing']);
        $this->assertEquals(76500.0, $report['data'][0]['debit']);
        $this->assertEquals(76500.0, $report['data'][0]['balance'], 'the running balance starts from the opening one');

        // A period that starts after the payment carries it forward as the opening balance instead.
        $later = $this->actingAsApi($accountant)->getJson("/api/v1/admin/reports/account-transactions?account_id={$cash->id}&from=".now('Asia/Dhaka')->addDay()->toDateString())->assertOk()->json('meta');
        $this->assertEquals([76500.0, 76500.0], [$later['opening'], $later['closing']]);

        $ledger = $this->actingAsApi($accountant)->getJson('/api/v1/admin/reports/general-ledger')->assertOk()->json();
        $this->assertEquals($ledger['meta']['totals']['debit'], $ledger['meta']['totals']['credit'], 'every entry has both sides');
        $this->assertGreaterThan(0, $ledger['meta']['totals']['debit']);
        $this->assertEquals(76500.0, collect($ledger['data'])->firstWhere('code', Account::CASH)['balance']);

        $csv = $this->actingAsApi($accountant)->get('/api/v1/admin/reports/general-ledger?format=csv')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('Code,Account,Kind,Debit,Credit,Balance', $csv->streamedContent());
    }

    /** A website booking, its invoice issued and paid in cash, so the journal has something real in it. */
    private function issueAndPayABooking(): Booking
    {
        $booking = Booking::query()->where('reference', $this->postJson('/api/v1/public/bookings', [
            'package_slug' => 'nepal-mustang-adventure-tour-8-days-7-nights', 'travel_date' => now()->addDays(40)->toDateString(),
            'pax' => 1, 'room' => 'twin', 'addons' => [], 'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711-000001']],
            'expected_total' => 76500, 'terms_accepted' => true, 'locale' => 'en',
        ])->assertCreated()->json('data.reference'))->firstOrFail();

        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/invoice")->assertOk();
        Storage::fake('local');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['amount' => 76500, 'method' => 'cash', 'evidence' => $this->receipt()])->assertOk();
        $this->assertSame('paid', Invoice::query()->where('booking_id', $booking->id)->value('payment_status'));

        return $booking->fresh();
    }

    /** @param list<array<string, mixed>> $lines */
    private function postEntry(Staff $staff, array $lines): void
    {
        $this->actingAsApi($staff)->postJson('/api/v1/admin/journal-entries', [
            'entry_date' => now('Asia/Dhaka')->toDateString(), 'description' => 'Test entry', 'lines' => $lines,
        ])->assertCreated();
    }
}
