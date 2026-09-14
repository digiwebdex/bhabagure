<?php

namespace Tests\Feature;

use App\Enums\PaymentAttemptStatus;
use App\Models\Account;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PaymentAttempt;
use App\Models\Staff;
use App\Models\Transaction;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Invoices\InvoicePdf;
use App\Services\Ledger\LedgerService;
use Database\Seeders\ContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-5-admin-core.md §4.6 and question 3 (full double entry): manual entries post balanced journal entries,
 * are corrected only by reversal and keep evidence private; method cards follow the Dhaka month; the company balance is
 * the journal and is refused (403) without its permission; deals are invoices paid through the ledger.
 */
class PaymentsBooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function a_manual_cash_out_posts_a_balanced_entry_keeps_its_evidence_private_and_is_corrected_only_by_reversal(): void
    {
        $accountant = $this->staff('accountant');

        $entry = $this->actingAsApi($accountant)->post('/api/v1/admin/cash-entries', [
            'direction' => 'out', 'amount' => 35000, 'method' => 'cash', 'category' => 'office_rent', 'business_line' => 'office',
            'description' => 'Office rent · September', 'reference' => 'Office rent', 'evidence' => UploadedFile::fake()->image('receipt.jpg', 600, 800),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.direction', 'out')->assertJsonPath('data.amount', 35000)->assertJsonPath('data.has_evidence', true)
            ->assertJsonPath('data.actions.reverse', true)->assertJsonMissingPath('data.evidence_path')->json('data');

        $this->assertSame([[Account::OFFICE_RENT, '35000.00', '0.00'], [Account::CASH, '0.00', '35000.00']], $this->journalLines(Transaction::query()->findOrFail($entry['id'])));
        $this->assertSame(-3500000, app(LedgerService::class)->moneyBalances()[Account::CASH]);
        $path = Transaction::query()->findOrFail($entry['id'])->evidence_path;
        $this->assertStringStartsWith('evidence/', $path);
        Storage::disk('local')->assertExists($path);

        // Evidence only through the signed-in route, only for staff who see payments.
        $this->actingAsApi($accountant)->get("/api/v1/admin/cash-book/{$entry['id']}/evidence")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAsApi($this->staff('sales_agent'))->getJson("/api/v1/admin/cash-book/{$entry['id']}/evidence")->assertForbidden();
        $this->resetAuthState();
        $this->flushHeaders()->getJson("/api/v1/admin/cash-book/{$entry['id']}/evidence")->assertUnauthorized();

        // A category belongs to a direction; every entry carries its receipt.
        $this->actingAsApi($accountant)->postJson('/api/v1/admin/cash-entries', ['direction' => 'in', 'amount' => 100, 'method' => 'cash', 'category' => 'office_rent', 'description' => 'Wrong way'])
            ->assertUnprocessable()->assertJsonValidationErrors(['category', 'evidence']);

        // ✕ is a reversing entry, once; a reversal isn't reversed.
        $reversal = $this->actingAsApi($accountant)->postJson("/api/v1/admin/cash-book/{$entry['id']}/reverse", ['reason' => 'Paid twice by mistake'])->assertCreated()
            ->assertJsonPath('data.direction', 'in')->assertJsonPath('data.reverses_id', $entry['id'])->json('data');
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/cash-book/{$entry['id']}/reverse", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'not_reversible');
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/cash-book/{$reversal['id']}/reverse", ['reason' => 'Undo the undo'])->assertStatus(409);
        $this->assertSame(0, app(LedgerService::class)->moneyBalances()[Account::CASH]);
        $this->assertSame(2, Transaction::query()->count());

        $rows = $this->actingAsApi($accountant)->getJson('/api/v1/admin/cash-book')->assertOk()->json('data');
        $original = collect($rows)->firstWhere('id', $entry['id']);
        $this->assertSame([$reversal['id'], false, 'reversed'], [$original['reversed_by']['id'], $original['actions']['reverse'], $original['reverse_blocked']]);
        $this->actingAsApi($accountant)->getJson('/api/v1/admin/cash-book?direction=out&category=office_rent')->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function method_cards_count_customer_money_in_the_dhaka_month_net_of_reversals(): void
    {
        $admin = $this->staff('admin');
        $booking = $this->invoicedBooking();
        $ledger = app(LedgerService::class);

        DB::transaction(function () use ($ledger, $booking, $admin) {
            // 23:30 on 30 September in Dhaka is September; 00:30 on 1 October is October though it is still 30 September in UTC.
            $ledger->recordPayment($booking, 50000, 'bkash', 'Advance', 'BK-1', $admin, occurredAt: Carbon::parse('2026-09-30 23:30', 'Asia/Dhaka'));
            $cash = $ledger->recordPayment($booking, 20000, 'cash', 'Cash at office', null, $admin, occurredAt: Carbon::parse('2026-09-12 11:00', 'Asia/Dhaka'));
            $ledger->recordPayment($booking, 30000, 'nagad', 'Second part', 'NG-1', $admin, occurredAt: Carbon::parse('2026-10-01 00:30', 'Asia/Dhaka'));
            Carbon::setTestNow(Carbon::parse('2026-09-20 10:00', 'Asia/Dhaka'));
            $ledger->reversePayment($cash, 'Counted twice', $admin);
            Carbon::setTestNow();
        });

        $september = $this->actingAsApi($admin)->getJson('/api/v1/admin/payments/summary?month=2026-09')->assertOk()->json('data');
        $cards = collect($september['methods'])->keyBy('key');
        $this->assertSame([50000, 1], [$cards['bkash']['amount'], $cards['bkash']['payments']]);
        $this->assertSame([0, 1], [$cards['cash_bank']['amount'], $cards['cash_bank']['payments']], 'net of the reversal; one payment');
        $this->assertSame([0, 0], [$cards['nagad']['amount'], $cards['nagad']['payments']]);
        $this->assertSame(50000, $september['collected']);
        $this->assertArrayNotHasKey('rocket', $cards->all(), 'no Rocket card without Rocket payments');

        $october = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/payments/summary?month=2026-10')->json('data.methods'))->keyBy('key');
        $this->assertSame([30000, 1], [$october['nagad']['amount'], $october['nagad']['payments']]);
    }

    #[Test]
    public function the_company_balance_is_the_journal_and_answers_403_without_its_permission(): void
    {
        [$accountant, $admin] = [$this->staff('accountant'), $this->staff('admin')];
        $viewer = $this->staffWith(['payments.view']);

        $this->actingAsApi($accountant)->postJson('/api/v1/admin/opening-balances', ['account' => Account::BANK, 'amount' => 1240000, 'as_of' => '2026-09-01', 'note' => 'City Bank statement'])
            ->assertCreated()->assertJsonPath('data.total', 1240000);
        $this->actingAsApi($admin)->postJson('/api/v1/admin/opening-balances', ['account' => Account::BANK, 'amount' => 1, 'as_of' => '2026-09-01'])
            ->assertStatus(409)->assertJsonPath('code', 'opening_exists');
        $this->actingAsApi($accountant)->postJson('/api/v1/admin/opening-balances', ['account' => Account::RECEIVABLE, 'amount' => 1, 'as_of' => '2026-09-01'])->assertUnprocessable();
        $this->assertSame('2026-09-01', JournalEntry::query()->where('source_type', 'account')->firstOrFail()->entry_date->toDateString());

        // A mistaken opening figure is corrected with an adjustment, not re-entered.
        $this->actingAsApi($accountant)->postJson('/api/v1/admin/cash-entries', ['direction' => 'out', 'amount' => 40000, 'method' => 'bank_transfer', 'category' => 'balance_adjustment', 'description' => 'Statement was ৳ 12,00,000', 'evidence' => $this->receipt('statement.jpg')])->assertCreated();

        $balance = $this->actingAsApi($accountant)->getJson('/api/v1/admin/payments/balance')->assertOk()->json('data');
        $this->assertSame(1200000, $balance['total']);
        $bank = collect($balance['accounts'])->firstWhere('code', Account::BANK);
        $this->assertSame([1200000, 1240000, '2026-09-01'], [$bank['balance'], $bank['opening']['amount'], $bank['opening']['as_of']]);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/payments/summary')->assertJsonPath('data.balance.total', 1200000);

        // Without ledger.view_company_balance: 403 on the endpoint, no figure in the summary, no opening balances.
        $this->actingAsApi($viewer)->getJson('/api/v1/admin/payments/balance')->assertForbidden();
        $this->actingAsApi($viewer)->getJson('/api/v1/admin/payments/summary')->assertOk()->assertJsonPath('data.balance', null);
        $this->actingAsApi($viewer)->postJson('/api/v1/admin/opening-balances', ['account' => Account::CASH, 'amount' => 1, 'as_of' => '2026-09-01'])->assertForbidden();
        // A sales agent and a tour operator reach none of it.
        foreach (['sales_agent', 'tour_operator'] as $role) {
            $staff = $this->staff($role);
            foreach (['/api/v1/admin/payments/balance', '/api/v1/admin/payments/summary', '/api/v1/admin/cash-book', '/api/v1/admin/deals'] as $path) {
                $this->actingAsApi($staff)->getJson($path)->assertForbidden();
            }
        }
    }

    #[Test]
    public function a_deal_is_a_standalone_invoice_paid_through_the_ledger_with_advance_and_due_derived(): void
    {
        $accountant = $this->staff('accountant');
        $this->actingAsApi($this->staff('sales_agent'))->postJson('/api/v1/admin/deals', [])->assertForbidden();

        $payload = [
            'company' => ['name' => 'Brac Bank Ltd', 'type' => 'corporate', 'contact_phone' => '01711-555666'],
            'title' => 'Sales team incentive tour', 'note' => 'Cox’s Bazar · 40 people', 'total' => 620000,
            'advance' => 200000, 'advance_method' => 'bank_transfer', 'advance_reference' => 'CITY-TT-8812',
        ];
        // The advance is money received: without its receipt nothing is created.
        $this->actingAsApi($accountant)->postJson('/api/v1/admin/deals', $payload)->assertUnprocessable()->assertJsonValidationErrors('advance_evidence');
        $this->assertSame(0, Invoice::query()->count());
        $deal = $this->actingAsApi($accountant)->postJson('/api/v1/admin/deals', $payload + ['advance_evidence' => $this->receipt('tt-slip.jpg')])->assertCreated()
            ->assertJsonPath('data.status', 'issued')->assertJsonPath('data.party.name', 'Brac Bank Ltd')->assertJsonPath('data.party.type', 'client')
            ->assertJsonPath('data.paid_amount', 200000)->assertJsonPath('data.balance_due', 420000)->assertJsonPath('data.payment_status', 'partial')->json('data');
        $this->assertMatchesRegularExpression('/^INV-\d{4}$/', $deal['number']);
        $invoice = Invoice::query()->findOrFail($deal['id']);
        $this->assertSame([null, 'deal', '8801711555666'], [$invoice->customer_id, $invoice->kind, $invoice->billed_phone]);
        $this->assertSame([[Account::RECEIVABLE, '620000.00', '0.00'], [Account::DEAL_SALES, '0.00', '620000.00']], $this->journalLines($invoice));

        $this->assertNotNull(Transaction::query()->where('invoice_id', $invoice->id)->value('evidence_path'));
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/deals/{$deal['id']}/payments", ['amount' => 420000, 'method' => 'bkash'])->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/deals/{$deal['id']}/payments", ['amount' => 500000, 'method' => 'bkash', 'evidence' => $this->receipt()])->assertUnprocessable()->assertJsonPath('code', 'exceeds_due');
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/deals/{$deal['id']}/payments", ['amount' => 420000, 'method' => 'bkash', 'reference' => '9FK2M4', 'evidence' => $this->receipt()])
            ->assertCreated()->assertJsonPath('data.balance_due', 0)->assertJsonPath('data.payment_status', 'paid')->assertJsonCount(2, 'data.payments');
        $this->actingAsApi($accountant)->getJson('/api/v1/admin/deals')->assertJsonPath('meta.total', 0)->assertJsonPath('meta.total_due', 0);
        $this->assertSame(0, app(LedgerService::class)->invoicePaidPaisa($invoice) - 62000000);

        // The printed invoice names the service, not a package or booking; payments are this invoice's own.
        $html = app(InvoicePdf::class)->html($invoice->fresh(), true, 'en');
        $this->assertStringContainsString('Service · সেবা', $html);
        $this->assertStringContainsString('Sales team incentive tour', $html);
        $at = stripos($html, 'booking');
        $this->assertFalse($at, 'no booking on a deal invoice: …'.substr($html, max(0, (int) $at - 120), 240));
        $this->assertStringContainsString('CITY-TT-8812', $html);

        // Void only once nothing is paid: reverse both payments, then void — the receivable goes back to zero.
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/deals/{$deal['id']}/void", ['reason' => 'Tour cancelled'])->assertStatus(409)->assertJsonPath('code', 'has_payments');
        foreach (Transaction::query()->where('invoice_id', $invoice->id)->pluck('id') as $id) {
            $this->actingAsApi($accountant)->postJson("/api/v1/admin/cash-book/{$id}/reverse", ['reason' => 'Tour cancelled, refunded'])->assertCreated();
        }
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/deals/{$deal['id']}/void", ['reason' => 'Tour cancelled'])->assertOk()->assertJsonPath('data.status', 'void');
        $receivable = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.code', Account::RECEIVABLE)
            ->selectRaw('SUM(debit) - SUM(credit) AS balance')->value('balance');
        $this->assertSame(0.0, (float) $receivable);
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/deals/{$deal['id']}/payments", ['amount' => 1, 'method' => 'cash', 'evidence' => $this->receipt()])->assertStatus(409);
    }

    #[Test]
    public function online_payments_that_need_a_person_stay_listed_until_marked_reviewed(): void
    {
        $accountant = $this->staff('accountant');
        $booking = $this->invoicedBooking();
        $attempt = fn (PaymentAttemptStatus $status, ?string $reason, float $surcharge = 0) => PaymentAttempt::query()->create([
            'booking_id' => $booking->id, 'gateway' => 'sslcommerz', 'tran_id' => 'T-'.uniqid(), 'amount' => 153000, 'status' => $status,
            'failure_reason' => $reason, 'gateway_surcharge' => $surcharge, 'expires_at' => now()->addHour(), 'closed_at' => now(),
        ]);
        $below = $attempt(PaymentAttemptStatus::NeedsReview, 'amount_below_expected');
        $attempt(PaymentAttemptStatus::Settled, 'gateway_surcharge', 3060);
        $attempt(PaymentAttemptStatus::Settled, null);

        $this->actingAsApi($accountant)->getJson('/api/v1/admin/payment-attempts/review')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/payment-attempts/{$below->id}/review", [])->assertUnprocessable();
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/payment-attempts/{$below->id}/review", ['note' => 'Customer paid the rest in cash'])->assertNoContent();
        $this->actingAsApi($accountant)->getJson('/api/v1/admin/payment-attempts/review')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'gateway_surcharge');
        $this->actingAsApi($accountant)->getJson('/api/v1/admin/payments/summary')->assertJsonPath('data.review_count', 1);
        $this->assertSame($accountant->id, $below->fresh()->reviewed_by_staff_id);
    }

    #[Test]
    public function saved_references_are_added_and_removed_by_staff_who_record_manual_entries(): void
    {
        $accountant = $this->staff('accountant');
        $id = $this->actingAsApi($accountant)->postJson('/api/v1/admin/reference-presets', ['label' => 'Pokhara Grande advance', 'direction' => 'out'])->assertCreated()->json('data.id');
        $this->actingAsApi($accountant)->postJson('/api/v1/admin/reference-presets', ['label' => 'Pokhara Grande advance'])->assertUnprocessable();
        $viewer = $this->staffWith(['payments.view']);
        $this->actingAsApi($viewer)->getJson('/api/v1/admin/reference-presets')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($viewer)->postJson('/api/v1/admin/reference-presets', ['label' => 'Office rent'])->assertForbidden();
        $this->actingAsApi($viewer)->deleteJson("/api/v1/admin/reference-presets/{$id}")->assertForbidden();
        $this->actingAsApi($accountant)->deleteJson("/api/v1/admin/reference-presets/{$id}")->assertNoContent();
    }

    private function invoicedBooking(): Booking
    {
        $booking = app(BookingCreator::class)->create(new BookingRequest(
            'nepal-mustang-adventure-tour-8-days-7-nights', now('Asia/Dhaka')->addDays(60)->toDateString(), 2, 'twin', [],
            [['name' => 'Rahim Uddin', 'passportNumber' => null, 'dateOfBirth' => null, 'passportExpiry' => null, 'phone' => '8801711000999', 'email' => null], ['name' => 'Karima Begum', 'passportNumber' => null, 'dateOfBirth' => null, 'passportExpiry' => null, 'phone' => null, 'email' => null]],
            153000, 'bn', 'website_form', true,
        ))['booking'];
        app(InvoiceIssuer::class)->issueForBooking($booking);

        return $booking->fresh();
    }

    /** @param  string[]  $permissions */
    private function staffWith(array $permissions): Staff
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = $this->staff(null);
        $staff->givePermissionTo($permissions);

        return $staff;
    }

    /** @return list<array{0: string, 1: string, 2: string}> [account code, debit, credit] of the source's first journal entry */
    private function journalLines(object $source): array
    {
        $entry = JournalEntry::query()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->orderBy('id')->firstOrFail();

        return $entry->lines()->with('account')->orderBy('id')->get()->map(fn ($line) => [$line->account->code, $line->debit, $line->credit])->all();
    }
}
