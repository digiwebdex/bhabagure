<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Account;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\PackageDeparture;
use App\Models\SeatHold;
use App\Models\TourPackage;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\BookingStateMachine;
use App\Services\Booking\BookingTransitionRefused;
use App\Services\Booking\PriceChanged;
use App\Services\Booking\SeatsUnavailable;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\PaymentExceedsBalance;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/phase-3-booking.md §3 and §6: one price, a derived payment status, and a balanced append-only ledger. */
class BookingLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function the_server_recomputes_the_price_and_refuses_a_total_the_customer_did_not_see(): void
    {
        // 4 travellers, single rooms, insurance: slab −6% → 70,500 pp; +12% supplement 8,460 pp; insurance 1,200 pp;
        // lines 320,640; 2% service charge on that = 6,413 (rounded); total 327,053. Same as the website's quote.
        $created = $this->book(pax: 4, room: 'single', addons: ['travel-insurance'], expectedTotal: 327053);
        $booking = $created['booking'];

        $this->assertMatchesRegularExpression('/^BH-\d{4}-001$/', $booking->reference);
        $this->assertSame(['327053.00', '282000.00', '33840.00', '4800.00', '6413.00'], [
            $booking->total_amount, $booking->subtotal_amount, $booking->single_supplement_amount, $booking->addons_amount, $booking->vat_amount,
        ]);
        $this->assertSame(BookingStatus::Inquiry, $booking->status);
        $this->assertSame('unpaid', $booking->payment_status);
        $this->assertSame(['package', 'single_supplement', 'addon'], $booking->lines->pluck('kind')->all());
        $this->assertSame('2026-11-07', $booking->travel_end->toDateString());

        try {
            $this->book(pax: 4, room: 'single', addons: ['travel-insurance'], expectedTotal: 320640);
            $this->fail('A stale total was accepted.');
        } catch (PriceChanged $e) {
            $this->assertSame(327053, $e->quote['total']);
        }
        $this->assertSame(1, Booking::query()->count());
    }

    #[Test]
    public function a_booking_is_confirmed_only_with_money_on_the_books_and_paid_follows_the_ledger(): void
    {
        $booking = $this->book()['booking'];
        $staff = $this->staff();

        $this->assertRefusedWith(fn () => app(BookingStateMachine::class)->confirm($booking, $staff), BookingTransitionRefused::class);
        $this->assertRefusedWith(fn () => DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 1000, 'cash', 'Advance')), LogicException::class);

        $invoice = app(InvoiceIssuer::class)->issueForBooking($booking, $staff);
        $this->assertSame('INV-0001', $invoice->invoice_number);
        $this->assertSame($invoice->id, app(InvoiceIssuer::class)->issueForBooking($booking, $staff)->id, 'issuing twice returns the same invoice');

        DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 50000, 'bkash', 'Advance', 'BKASH-1', $staff));
        $this->assertSame(['50000.00', 'partial', '103000.00'], [$booking->fresh()->paid_amount, $booking->fresh()->payment_status, $booking->fresh()->due_amount]);
        $this->assertSame(['50000.00', 'partial', '103000.00'], [$invoice->fresh()->paid_amount, $invoice->fresh()->payment_status, $invoice->fresh()->balance_due]);

        $this->assertRefusedWith(fn () => DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 103001, 'cash', 'Too much')), PaymentExceedsBalance::class);

        app(BookingStateMachine::class)->confirm($booking, $staff);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 103000, 'cash', 'Balance', null, $staff));
        $this->assertSame(['153000.00', 'paid', '0.00'], [$booking->fresh()->paid_amount, $booking->fresh()->payment_status, $booking->fresh()->due_amount]);

        $this->assertJournalBalances();
        $this->assertSame(0.0, $this->accountBalance(Account::RECEIVABLE), 'fully paid: nothing left receivable');
        $this->assertSame(-150000.0, $this->accountBalance(Account::PACKAGE_SALES));
        $this->assertSame(-3000.0, $this->accountBalance(Account::VAT_PAYABLE));
    }

    #[Test]
    public function status_and_paid_amount_cannot_be_set_by_hand(): void
    {
        $booking = $this->book()['booking'];

        $this->assertRefusedWith(fn () => $booking->forceFill(['status' => 'confirmed'])->save(), LogicException::class);
        $this->assertRefusedWith(fn () => $booking->fresh()->forceFill(['paid_amount' => 153000])->save(), LogicException::class);
        $this->assertRefusedWith(fn () => $booking->fresh()->forceFill(['payment_status' => 'paid'])->save(), LogicException::class);
        $this->assertRefusedWith(fn () => app(BookingStateMachine::class)->complete($booking), BookingTransitionRefused::class);
    }

    #[Test]
    public function a_mistaken_payment_is_reversed_not_edited(): void
    {
        $booking = $this->book()['booking'];
        $staff = $this->staff();
        app(InvoiceIssuer::class)->issueForBooking($booking, $staff);
        $payment = DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 20000, 'cash', 'Typo', null, $staff));

        DB::transaction(fn () => app(LedgerService::class)->reversePayment($payment, 'Wrong booking', $staff));

        $this->assertSame(['0.00', 'unpaid'], [$booking->fresh()->paid_amount, $booking->fresh()->payment_status]);
        $this->assertSame(2, DB::table('transactions')->where('booking_id', $booking->id)->count());
        $this->assertRefusedWith(fn () => DB::transaction(fn () => app(LedgerService::class)->reversePayment($payment, 'Again', $staff)), LogicException::class);
        $this->assertJournalBalances();
        $this->assertSame(153000.0, $this->accountBalance(Account::RECEIVABLE));
    }

    #[Test]
    public function an_issued_invoice_keeps_its_snapshot_when_the_package_changes_and_voiding_reverses_the_journal(): void
    {
        $booking = $this->book()['booking'];
        $staff = $this->staff();
        $invoice = app(InvoiceIssuer::class)->issueForBooking($booking, $staff);
        $title = $booking->package_title_en;

        TourPackage::query()->where('slug', self::MUSTANG)->update(['title_en' => 'Renamed next season', 'sale_price' => 99000]);

        $fresh = $invoice->fresh();
        $this->assertSame([$title, '153000.00', '3000.00', 2], [$fresh->package_title_en, $fresh->total_amount, $fresh->vat_amount, $fresh->pax_count]);
        $this->assertSame([['name' => 'Tanvir Hasan', 'passportNumber' => 'A01234567', 'passportExpiry' => '2030-01-31'], ['name' => 'Nusrat Jahan', 'passportNumber' => 'B07654321', 'passportExpiry' => '2031-05-01']], $fresh->travellers);
        $this->assertSame([[$title, '150000.00']], $fresh->items->map(fn ($item) => [$item->title_en, $item->line_total])->all());

        app(InvoiceIssuer::class)->void($invoice, 'Reissue with new address', $staff);
        $this->assertSame(Invoice::VOID, $invoice->fresh()->status);
        $this->assertJournalBalances();
        $this->assertSame(0.0, $this->accountBalance(Account::RECEIVABLE));

        $reissued = app(InvoiceIssuer::class)->issueForBooking($booking, $staff);
        $this->assertSame('INV-0002', $reissued->invoice_number);
    }

    #[Test]
    public function seats_are_held_while_paying_and_never_oversold(): void
    {
        $package = TourPackage::query()->where('slug', self::MUSTANG)->firstOrFail();
        $departure = PackageDeparture::query()->create(['tour_package_id' => $package->id, 'departs_on' => '2026-10-31', 'seats_total' => 5, 'status' => 'scheduled']);

        $first = $this->book(pax: 3, expectedTotal: 222615)['booking'];
        $this->assertSame($departure->id, $first->departure_id);
        $this->assertSame(3, (int) SeatHold::query()->active()->sum('seats'));

        $this->assertRefusedWith(fn () => $this->book(pax: 3, expectedTotal: 222615), SeatsUnavailable::class);

        // The hold expires (payment abandoned): the seats come back, the unpaid booking stays.
        SeatHold::query()->update(['expires_at' => now()->subMinute()]);
        $second = $this->book(pax: 3, expectedTotal: 222615)['booking'];
        $this->assertSame(2, Booking::query()->count());

        // Paying and confirming turns the hold into a sold seat.
        $staff = $this->staff();
        app(InvoiceIssuer::class)->issueForBooking($second, $staff);
        DB::transaction(fn () => app(LedgerService::class)->recordPayment($second, 222615, 'cash', 'Full', null, $staff));
        app(BookingStateMachine::class)->confirm($second, $staff);
        $this->assertNotNull(SeatHold::query()->where('booking_id', $second->id)->value('converted_at'));
        $this->assertSame(0, (int) SeatHold::query()->active()->sum('seats'));
        $this->assertRefusedWith(fn () => $this->book(pax: 3, expectedTotal: 222615), SeatsUnavailable::class);

        app(BookingStateMachine::class)->cancel($first, 'Customer changed plans', $staff);
        $this->assertSame(BookingStatus::Cancelled, $first->fresh()->status);
    }

    #[Test]
    public function a_confirmed_trip_is_completed_the_day_after_it_ends(): void
    {
        $booking = $this->book()['booking'];
        $staff = $this->staff();
        app(InvoiceIssuer::class)->issueForBooking($booking, $staff);
        DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 153000, 'cash', 'Full', null, $staff));
        app(BookingStateMachine::class)->confirm($booking, $staff);
        $inquiry = $this->book(expectedTotal: 153000)['booking'];

        $this->travelTo(Carbon::parse('2026-11-07 23:00', 'Asia/Dhaka'));
        $this->artisan('bookings:complete-travelled')->assertSuccessful();
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status, 'not before the last day is over');

        $this->travelTo(Carbon::parse('2026-11-08 09:00', 'Asia/Dhaka'));
        $this->artisan('bookings:complete-travelled')->assertSuccessful();
        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
        $this->assertSame(BookingStatus::Inquiry, $inquiry->fresh()->status, 'an unpaid inquiry is left for sales');
    }

    /** @return array{booking: Booking, accessToken: string, quote: array<string, mixed>} */
    private function book(int $pax = 2, string $room = 'twin', array $addons = [], int $expectedTotal = 153000): array
    {
        $travellers = [
            ['name' => 'Tanvir Hasan', 'passportNumber' => 'a0123 4567', 'dateOfBirth' => '1990-04-12', 'passportExpiry' => '2030-01-31', 'phone' => '8801711000001', 'email' => 'tanvir@example.test'],
            ['name' => 'Nusrat Jahan', 'passportNumber' => 'B07654321', 'dateOfBirth' => '1992-08-03', 'passportExpiry' => '2031-05-01', 'phone' => null, 'email' => null],
        ];
        while (count($travellers) < $pax) {
            $travellers[] = ['name' => 'Traveller '.count($travellers), 'passportNumber' => null, 'dateOfBirth' => null, 'passportExpiry' => null, 'phone' => null, 'email' => null];
        }

        return app(BookingCreator::class)->create(new BookingRequest(
            self::MUSTANG, '2026-10-31', $pax, $room, $addons, array_slice($travellers, 0, $pax), $expectedTotal, 'en', 'website', true,
        ));
    }

    private function assertJournalBalances(): void
    {
        $this->assertSame((float) JournalLine::query()->sum('debit'), (float) JournalLine::query()->sum('credit'));
    }

    /** Debit-positive balance of one account. */
    private function accountBalance(string $code): float
    {
        return (float) JournalLine::query()->whereHas('account', fn ($query) => $query->where('code', $code))->selectRaw('COALESCE(SUM(debit - credit), 0) AS balance')->value('balance');
    }

    private function assertRefusedWith(callable $callback, string $exception): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());

            return;
        }
        $this->fail("Expected {$exception}.");
    }
}
