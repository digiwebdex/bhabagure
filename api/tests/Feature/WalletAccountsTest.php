<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ledger\LedgerService;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * bKash, Nagad and Rocket each have their own journal account (decided 2026-09-14), so each provider's statement
 * reconciles against the books. Money posted to the old shared account before the split is moved by reclassifying
 * entries, never by editing the journal.
 */
class WalletAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function each_wallet_provider_posts_to_its_own_account_and_shows_separately_in_the_balance(): void
    {
        $admin = $this->staff('admin');
        $booking = $this->invoicedBooking();
        DB::transaction(function () use ($booking, $admin) {
            $ledger = app(LedgerService::class);
            $ledger->recordPayment($booking, 30000, 'bkash', 'bKash part', 'BK-1', $admin);
            $ledger->recordPayment($booking, 20000, 'nagad', 'Nagad part', 'NG-1', $admin);
            $ledger->recordPayment($booking, 10000, 'rocket', 'Rocket part', 'RK-1', $admin);
        });

        $this->assertSame(
            [Account::BKASH => 3000000, Account::NAGAD => 2000000, Account::ROCKET => 1000000],
            array_intersect_key(app(LedgerService::class)->moneyBalances(), array_flip([Account::BKASH, Account::NAGAD, Account::ROCKET])),
        );
        $this->assertArrayNotHasKey(Account::MOBILE_WALLETS, app(LedgerService::class)->moneyBalances(), 'nothing left on the old shared account');

        $accounts = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/payments/balance')->assertOk()->json('data.accounts'))->keyBy('code');
        $this->assertSame([30000, 20000, 10000], [$accounts[Account::BKASH]['balance'], $accounts[Account::NAGAD]['balance'], $accounts[Account::ROCKET]['balance']]);
        $this->assertSame(['bKash', 'Nagad', 'Rocket'], [$accounts[Account::BKASH]['name_en'], $accounts[Account::NAGAD]['name_en'], $accounts[Account::ROCKET]['name_en']]);
    }

    #[Test]
    public function money_on_the_old_shared_account_is_reclassified_by_provider_and_only_an_unattributable_rest_stays(): void
    {
        $admin = $this->staff('admin');
        $booking = $this->invoicedBooking();
        // Before the split: two payments and an opening balance, all on Mobile wallets.
        $bkash = $this->preSplitPayment($booking, 'bkash', 50000);
        $nagad = $this->preSplitPayment($booking, 'nagad', 20000);
        $this->preSplitLine(Account::MOBILE_WALLETS, 5000, null);
        $before = array_sum(app(LedgerService::class)->moneyBalances());
        $entries = JournalEntry::query()->count();

        (require database_path('migrations/2026_09_14_140000_split_mobile_wallet_accounts.php'))->up();

        $balances = app(LedgerService::class)->moneyBalances();
        $this->assertSame([5000000, 2000000, 0], [$balances[Account::BKASH], $balances[Account::NAGAD], $balances[Account::ROCKET]]);
        $this->assertSame(500000, $balances[Account::MOBILE_WALLETS], 'the opening balance has no provider: it stays, visible');
        $this->assertSame($before, array_sum($balances), 'reclassifying moves money between accounts; the total is unchanged');
        $this->assertSame($entries + 2, JournalEntry::query()->count(), 'one reclassifying entry per provider with a balance');
        $this->assertSame(2, Transaction::query()->whereIn('id', [$bkash, $nagad])->count(), 'the cash book is untouched');

        $legacy = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/payments/balance')->json('data.accounts'))->firstWhere('code', Account::MOBILE_WALLETS);
        $this->assertSame([5000, true], [$legacy['balance'], $legacy['legacy']]);
    }

    private function invoicedBooking(): Booking
    {
        $booking = app(BookingCreator::class)->create(new BookingRequest(
            'nepal-mustang-adventure-tour-8-days-7-nights', now('Asia/Dhaka')->addDays(60)->toDateString(), 2, 'twin', [],
            [['name' => 'Wallet Payer', 'passportNumber' => null, 'dateOfBirth' => null, 'passportExpiry' => null, 'phone' => '8801711000701', 'email' => null], ['name' => 'Second', 'passportNumber' => null, 'dateOfBirth' => null, 'passportExpiry' => null, 'phone' => null, 'email' => null]],
            153000, 'bn', 'website_form', true,
        ))['booking'];
        app(InvoiceIssuer::class)->issueForBooking($booking);

        return $booking->fresh();
    }

    /** A cash-book row and its journal entry as the ledger wrote them before the split: Dr Mobile wallets · Cr receivable. */
    private function preSplitPayment(Booking $booking, string $method, int $amount): int
    {
        $row = Transaction::query()->create([
            'direction' => 'in', 'amount' => $amount, 'category' => LedgerService::CATEGORY_PAYMENT, 'method' => $method, 'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id, 'description' => 'Before the split', 'occurred_at' => now()->subDay(),
        ]);
        $entry = $this->preSplitLine(Account::MOBILE_WALLETS, $amount, $row);
        DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'account_id' => Account::query()->where('code', Account::RECEIVABLE)->value('id'), 'debit' => 0, 'credit' => $amount, 'created_at' => now()]);

        return $row->id;
    }

    private function preSplitLine(string $code, int $amount, ?Transaction $source): int
    {
        $entry = DB::table('journal_entries')->insertGetId([
            'entry_date' => now()->subDay()->toDateString(), 'description' => 'Before the split',
            'source_type' => $source ? 'transaction' : 'account', 'source_id' => $source?->id ?? Account::query()->where('code', $code)->value('id'), 'created_at' => now(),
        ]);
        DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'account_id' => Account::query()->where('code', $code)->value('id'), 'debit' => $amount, 'credit' => 0, 'created_at' => now()]);
        if ($source === null) {
            DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'account_id' => Account::query()->where('code', Account::OPENING_BALANCES)->value('id'), 'debit' => 0, 'credit' => $amount, 'created_at' => now()]);
        }

        return $entry;
    }
}
