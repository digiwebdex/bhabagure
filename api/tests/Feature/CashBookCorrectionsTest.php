<?php

namespace Tests\Feature;

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use App\Support\Ledger\CashCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/transaction-edits.md: Edit and Delete on the cash book (client, 2026-10-01). Nothing is overwritten — the entry is
 * cancelled on its own date and, for an edit, the corrected one recorded — and every balance follows.
 */
class CashBookCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    #[Test]
    public function an_edit_cancels_the_entry_on_its_own_date_and_records_the_corrected_one(): void
    {
        $admin = $this->staff('admin');
        // Recorded on 3 September: 35,000 office rent, from cash.
        $id = $this->actingAsApi($admin)->post('/api/v1/admin/cash-entries', [
            'direction' => 'out', 'amount' => 35000, 'account' => Account::CASH, 'category' => 'office_rent', 'description' => 'Office rent · September',
            'reference' => 'RENT-09', 'occurred_on' => '2026-09-03', 'evidence' => $this->receipt(),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.actions.edit', true)->assertJsonPath('data.actions.delete', true)->json('data.id');
        $same = ['occurred_on' => '2026-09-03', 'account' => Account::CASH, 'category' => 'office_rent', 'description' => 'Office rent · September', 'reference' => 'RENT-09', 'amount' => 35000];

        // Only transactions.edit changes the books: the accountant who records them can't.
        $this->actingAsApi($this->staff('accountant'))->postJson("/api/v1/admin/cash-book/{$id}/edit", ['amount' => 30000] + $same)->assertForbidden();
        // Nothing changed: refused.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/cash-book/{$id}/edit", $same)->assertUnprocessable()->assertJsonValidationErrors('amount');

        // It was really 30,000 of electricity, paid from the bank on 2 September.
        $edited = $this->actingAsApi($admin)->postJson("/api/v1/admin/cash-book/{$id}/edit", [
            'occurred_on' => '2026-09-02', 'account' => Account::BANK, 'category' => 'utilities', 'description' => 'Electricity · September',
            'reference' => 'DESCO-09', 'amount' => 30000, 'reason' => 'Wrong bill entered',
        ])->assertCreated()->assertJsonPath('data.amount', 30000)->assertJsonPath('data.category', 'utilities')->assertJsonPath('data.account.code', Account::BANK)
            ->assertJsonPath('data.reference', 'DESCO-09')->assertJsonPath('data.has_evidence', true)
            ->assertJsonPath('data.edited.from_id', $id)->assertJsonPath('data.edited.reason', 'Wrong bill entered')->json('data');
        $this->assertSame(['2026-09-03', '2026-09-02'], $edited['edited']['changes']['date']);
        $this->assertSame([35000, 30000], $edited['edited']['changes']['amount']);
        $this->assertSame([Account::CASH, Account::BANK], $edited['edited']['changes']['account']);
        $this->assertSame('2026-09-02', Transaction::query()->findOrFail($edited['id'])->occurred_at->timezone('Asia/Dhaka')->toDateString());

        // The accounts: cash as if the rent had never been entered, the bank 30,000 down.
        $balances = app(LedgerService::class)->moneyBalances();
        $this->assertSame([0, -3000000], [$balances[Account::CASH], $balances[Account::BANK]]);

        // The cancelling entry carries the original's date, in the cash book and the journal: September reads right.
        $reversal = Transaction::query()->where('reverses_transaction_id', $id)->sole();
        $this->assertSame('2026-09-03', $reversal->occurred_at->timezone('Asia/Dhaka')->toDateString());
        $journal = fn (int $source) => JournalEntry::query()->where('source_type', (new Transaction)->getMorphClass())->where('source_id', $source)->sole()->entry_date->toDateString();
        $this->assertSame(['2026-09-03', '2026-09-02'], [$journal($reversal->id), $journal($edited['id'])]);

        // The book as it stands shows the corrected entry; the history shows all three.
        $this->assertSame([$edited['id']], array_column($this->actingAsApi($admin)->getJson('/api/v1/admin/cash-book')->json('data'), 'id'));
        $history = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/cash-book?history=1')->json('data'))->keyBy('id');
        $this->assertSame(['edit', $edited['id'], 'Wrong bill entered'], [$history[$id]['correction']['kind'], $history[$id]['correction']['replacement_id'], $history[$id]['correction']['reason']]);
        $this->assertSame($id, $history[$reversal->id]['reverses_id']);

        // Audited with what changed.
        $audit = AuditLog::query()->where('action', 'cash.edited')->sole();
        $this->assertSame([35000, 30000], $audit->changes['changes']['amount']);
        $this->assertSame($edited['id'], $audit->changes['replacement']);

        // The original can't be changed again; the corrected one can be edited, or deleted.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/cash-book/{$id}/edit", ['amount' => 1] + $same)->assertStatus(409)->assertJsonPath('code', 'reversed');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/cash-book/{$edited['id']}/reverse", ['reason' => 'The landlord paid it after all'])->assertCreated();
        $this->assertSame(0, app(LedgerService::class)->moneyBalances()[Account::BANK]);
        $this->assertSame(0, $this->actingAsApi($admin)->getJson('/api/v1/admin/cash-book')->json('meta.total'));
        $this->assertTrue(AuditLog::query()->where('action', 'cash.deleted')->where('changes->reason', 'The landlord paid it after all')->exists());
        // Deleted on its own date too.
        $this->assertSame('2026-09-02', Transaction::query()->where('reverses_transaction_id', $edited['id'])->sole()->occurred_at->timezone('Asia/Dhaka')->toDateString());
    }

    #[Test]
    public function an_edited_invoice_payment_moves_what_is_owed_and_never_passes_it(): void
    {
        $admin = $this->staff('admin');
        $customer = Customer::query()->forceCreate(['name' => 'Corporate Client', 'phone' => '8801711000900', 'stage' => 'customer', 'source' => 'walk_in']);
        $invoiceId = $this->actingAsApi($admin)->postJson('/api/v1/admin/invoices', [
            'customer_id' => $customer->id, 'title' => 'Visa processing', 'lines' => [['title' => 'Schengen visa', 'quantity' => 2, 'unit_price' => 25000]],
        ])->assertCreated()->json('data.id');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/invoices/{$invoiceId}/issue")->assertOk();
        $this->actingAsApi($admin)->post("/api/v1/admin/deals/{$invoiceId}/payments", ['amount' => 20000, 'method' => 'cash', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])
            ->assertCreated();
        $paymentId = Transaction::query()->where('invoice_id', $invoiceId)->value('id');
        $today = now('Asia/Dhaka')->toDateString();

        // 20,000 was really 25,000 by bKash: the invoice follows. A payment keeps its category whatever is sent.
        $newId = $this->actingAsApi($admin)->postJson("/api/v1/admin/cash-book/{$paymentId}/edit", ['occurred_on' => $today, 'account' => Account::BKASH, 'amount' => 25000, 'reference' => 'BK-77', 'category' => 'other_income'])
            ->assertCreated()->assertJsonPath('data.category', 'customer_payment')->assertJsonPath('data.invoice.id', $invoiceId)->json('data.id');
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertEquals([25000, 25000, 'partial'], [(float) $invoice->paid_amount, (float) $invoice->balance_due, $invoice->payment_status]);

        // More than the invoice comes to: refused, and nothing changes.
        $count = Transaction::query()->count();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/cash-book/{$newId}/edit", ['occurred_on' => $today, 'account' => Account::BKASH, 'amount' => 50001, 'reference' => 'BK-77'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame($count, Transaction::query()->count());
        $this->assertEquals(25000, (float) $invoice->fresh()->paid_amount);
    }

    #[Test]
    public function online_payments_vat_and_cancelling_entries_are_not_changed_here(): void
    {
        $admin = $this->staff('admin');
        $online = Transaction::query()->create([
            'direction' => TransactionDirection::In, 'amount' => 5000, 'category' => LedgerService::CATEGORY_PAYMENT, 'method' => 'sslcommerz',
            'description' => 'Card payment', 'occurred_at' => now(),
        ]);
        $vat = Transaction::query()->create([
            'direction' => TransactionDirection::Out, 'amount' => 900, 'category' => CashCategories::VAT_PAID, 'method' => 'bank_transfer',
            'description' => 'VAT for August', 'occurred_at' => now(),
        ]);
        $edit = ['occurred_on' => now('Asia/Dhaka')->toDateString(), 'account' => Account::BANK, 'amount' => 1, 'description' => 'Changed'];

        foreach ([[$online, 'online'], [$vat, 'vat']] as [$entry, $code]) {
            $this->actingAsApi($admin)->postJson("/api/v1/admin/cash-book/{$entry->id}/edit", $edit)->assertStatus(409)->assertJsonPath('code', $code);
            $this->actingAsApi($admin)->postJson("/api/v1/admin/cash-book/{$entry->id}/reverse", ['reason' => 'Mistake'])->assertStatus(409)->assertJsonPath('code', $code);
        }
        $rows = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/cash-book')->json('data'))->keyBy('id');
        $this->assertSame([false, false, 'online', 'online'], [$rows[$online->id]['actions']['edit'], $rows[$online->id]['actions']['delete'], $rows[$online->id]['edit_blocked'], $rows[$online->id]['delete_blocked']]);
        $this->assertSame(['vat', 'vat'], [$rows[$vat->id]['edit_blocked'], $rows[$vat->id]['delete_blocked']]);
    }
}
