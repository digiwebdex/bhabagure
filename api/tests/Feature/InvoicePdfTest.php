<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SiteSetting;
use App\Models\TourPackage;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Invoices\InvoicePdf;
use App\Services\Ledger\LedgerService;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * docs/phase-3-booking.md §6, verified on the PDF itself: text positions from the PDF, blank space from the rasterised
 * page, and the barcode decoded from pixels (packages/pdf/src/inspect.mjs).
 */
class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    /** Page top padding (6 mm) + header block (42 mm). */
    private const LETTERHEAD_BOTTOM_MM = 48.0;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ContentSeeder::class);
        // With the notifications number published the footer has one more line; the layout checks run with it.
        SiteSetting::query()->where('key', 'contact')->firstOrFail()
            ->forceFill(['value' => ['notificationsWhatsapp' => '+8801911000111'] + SiteSetting::get('contact')])->save();

        $created = app(BookingCreator::class)->create(new BookingRequest(
            'nepal-mustang-adventure-tour-8-days-7-nights', '2026-10-31', 2, 'single', ['travel-insurance', 'airport-pickup'],
            [
                ['name' => 'Md Tanvir Hasan', 'passportNumber' => 'BW0912345', 'dateOfBirth' => '1990-04-12', 'passportExpiry' => '2031-03-12', 'phone' => '8801711223344', 'email' => 'tanvir.hasan@example.test'],
                ['name' => 'Nusrat Jahan', 'passportNumber' => 'BX4471228', 'dateOfBirth' => '1992-08-03', 'passportExpiry' => '2029-07-11', 'phone' => null, 'email' => null],
            ],
            // 2 pax at 75,000 + the package's 15% single 11,250 pp + insurance 2,400 + pickup 800 = 175,700; 2% = 3,514.
            179214, 'bn', 'website', true,
        ));
        $staff = $this->staff();
        $this->invoice = app(InvoiceIssuer::class)->issueForBooking($created['booking'], $staff);
        DB::transaction(fn () => app(LedgerService::class)->recordPayment($created['booking'], 100000, 'bkash', 'Advance', '8FK2M4QX', $staff));
        $this->invoice->refresh();
    }

    #[Test]
    public function turning_the_header_off_leaves_the_top_blank_and_moves_nothing_below(): void
    {
        $on = $this->inspect($this->renderPdf(true), inkBottomMm: self::LETTERHEAD_BOTTOM_MM);
        $off = $this->inspect($this->renderPdf(false), inkBottomMm: self::LETTERHEAD_BOTTOM_MM);

        $this->assertSame([1, 1], [$on['pages'], $off['pages']], 'one A4 page');
        $this->assertEqualsWithDelta(210.0, $on['widthMm'], 0.5);
        $this->assertEqualsWithDelta(297.0, $on['heightMm'], 0.5);

        // The letterhead block prints with the header on, and is empty paper with it off.
        $this->assertGreaterThan(1000, $on['ink']['darkPixels']);
        $this->assertSame(0, $off['ink']['darkPixels'], "ink found at {$off['ink']['firstDarkRowMm']} mm with the header off");
        $this->assertNotContains('Bhabaghure Holidays Aviation', array_column($off['texts'], 'str'));

        // Everything from the title down is at exactly the same place on the page.
        $below = fn (array $pdf) => array_values(array_filter($pdf['texts'], fn (array $t) => $t['yMm'] >= self::LETTERHEAD_BOTTOM_MM));
        $onBelow = $below($on);
        $offBelow = $below($off);
        $this->assertSame(array_column($onBelow, 'str'), array_column($offBelow, 'str'));
        foreach ($onBelow as $i => $text) {
            $this->assertEqualsWithDelta($text['yMm'], $offBelow[$i]['yMm'], 0.01, "\"{$text['str']}\" moved vertically");
            $this->assertEqualsWithDelta($text['xMm'], $offBelow[$i]['xMm'], 0.01, "\"{$text['str']}\" moved horizontally");
        }

        $billed = $this->find($off, 'Invoice To');
        $this->assertGreaterThan(self::LETTERHEAD_BOTTOM_MM, $billed['yMm'], 'content starts below the reserved space');
    }

    #[Test]
    public function the_barcode_scans_as_the_invoice_number(): void
    {
        $result = $this->inspect($this->renderPdf(false), barcode: true);

        $this->assertSame(['text' => $this->invoice->invoice_number, 'format' => 'CODE_128'], $result['barcode']);
    }

    #[Test]
    public function the_pdf_is_rendered_from_the_snapshot_not_the_package_as_it_is_now(): void
    {
        $before = $this->texts($this->renderPdf(true));

        TourPackage::query()->where('slug', 'nepal-mustang-adventure-tour-8-days-7-nights')
            ->update(['title_bn' => 'নতুন নাম', 'title_en' => 'Renamed next season', 'sale_price' => 99000, 'duration_days' => 12, 'duration_nights' => 11]);
        Storage::fake('local'); // no cached file: render again from the database

        $after = $this->texts($this->renderPdf(true));

        $this->assertSame($before, $after);
        // English only since 2026-09-19 (the client's decision): Latin digits, whatever the customer's language.
        $this->assertPdfContains('1,79,214', $after, 'total in English digits');
        // Not even the ৳ sign: amounts read "BDT".
        $this->assertDoesNotMatchRegularExpression('/[\x{0980}-\x{09FF}]/u', implode('', $after), 'no Bangla on the invoice');
        $this->assertPdfContains('BDT 1,79,214', $after, 'total in BDT');
        $this->assertStringNotContainsString('Renamed', implode('', $after));
    }

    #[Test]
    public function totals_and_the_status_pill_follow_the_ledger(): void
    {
        $texts = $this->texts($this->renderPdf(true));

        $this->assertPdfContains('PARTIAL', $texts);
        $this->assertPdfContains('1,00,000', $texts, 'paid');
        $this->assertPdfContains('79,214', $texts, 'balance due');
        $this->assertPdfContains('8FK2M4QX', $texts, 'payment reference');
        $this->assertPdfContains('Notifications: 01911000111', $texts, 'the notifications number beside the main line');
    }

    #[Test]
    public function a_bookings_invoice_downloads_under_its_number(): void
    {
        // Client, 2026-10-01: saved as INV-0001.pdf, not under the browser's random blob id.
        $admin = $this->staff('admin');
        $booking = $this->invoice->booking_id;

        $this->actingAsApi($admin)->get("/api/v1/admin/bookings/{$booking}/invoice/pdf")->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename=\"{$this->invoice->invoice_number}.pdf\"");
        $this->actingAsApi($admin)->get("/api/v1/admin/bookings/{$booking}/invoice/pdf?header=0")->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename=\"{$this->invoice->invoice_number}-pad.pdf\"");
    }

    #[Test]
    public function a_long_note_runs_on_to_further_pages_and_nothing_is_cut_off(): void
    {
        // Client, 2026-10-01: a long note flows on to the next page instead of being cut off.
        $admin = $this->staff('admin');
        $customer = Customer::query()->forceCreate(['name' => 'Corporate Client', 'phone' => '8801711000900', 'stage' => 'customer', 'source' => 'walk_in']);
        $clauses = array_map(fn (int $i) => "{$i}. Clause {$i}: cancelled 30 days or more before departure, the amount paid is refunded less the non-refundable airline and hotel deposits; within 29 days, no refund.", range(1, 45));
        // A reference with no space in it wraps at the margin instead of running off the paper.
        $reference = 'REF-'.str_repeat('7', 300);
        $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/invoices', [
            'customer_id' => $customer->id, 'title' => 'Umrah package, two people', 'note' => implode("\n", [...$clauses, $reference]),
            'lines' => [['title' => 'Umrah package', 'quantity' => 2, 'unit_price' => 150000]],
        ])->assertCreated()->json('data.id');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/invoices/{$id}/issue")->assertOk();
        $invoice = Invoice::query()->findOrFail($id);

        // Printed on a pre-printed pad (header off): every sheet has the letterhead, so page 2 leaves it blank too.
        $pdf = $this->inspect($this->renderPdf(false, $invoice), inkBottomMm: self::LETTERHEAD_BOTTOM_MM, page: 2);
        $this->assertGreaterThanOrEqual(2, $pdf['pages']);
        $this->assertSame(0, $pdf['ink']['darkPixels'], "ink at {$pdf['ink']['firstDarkRowMm']} mm on page 2");

        // Every clause is printed whole and in order, starting on the first page under the figures.
        $body = array_filter($pdf['texts'], fn (array $text) => ! str_contains($text['str'], ' · Page '));
        $printed = preg_replace('/\s+/u', '', implode('', array_column($body, 'str')));
        $from = 0;
        foreach ([...$clauses, $reference] as $clause) {
            $at = mb_strpos($printed, preg_replace('/\s+/u', '', $clause), $from);
            $this->assertNotFalse($at, "not printed: {$clause}");
            $from = $at;
        }
        $this->assertSame(1, $this->find($pdf, 'Notes / Terms')['page']);

        // On every page, nothing within 5 mm of the paper's top or bottom edge and nothing past the side margins.
        foreach ($pdf['texts'] as $text) {
            $where = "\"{$text['str']}\" on page {$text['page']}";
            $this->assertGreaterThanOrEqual(5.0, $text['yMm'], $where);
            $this->assertLessThanOrEqual(292.0, $text['baselineMm'], $where);
            $this->assertGreaterThanOrEqual(8.9, $text['xMm'], $where);
            $this->assertLessThanOrEqual(201.2, $text['rightMm'], $where);
        }

        // A further page says whose it is and how many there are; the signatures close the last page.
        $this->assertPdfContains("{$invoice->invoice_number} · Page 2 of {$pdf['pages']}", array_column(array_filter($pdf['texts'], fn (array $text) => $text['page'] === 2), 'str'));
        $this->assertSame($pdf['pages'], $this->find($pdf, 'Customer Signature')['page']);

        // At its foot, and only there (client, 2026-10-01): the thank-you, the block's last line, ends at the bottom of the
        // text area (297 − 12 mm), and the signatures sit just above it — not straight after the last of the notes.
        $last = collect($pdf['texts'])->where('page', $pdf['pages'])->reject(fn (array $text) => str_contains($text['str'], ' · Page '));
        $this->assertEqualsWithDelta(283.0, $last->max('baselineMm'), 3.0, 'the thank-you at the foot of the last page');
        $this->assertGreaterThan(240.0, $this->find($pdf, 'Customer Signature')['baselineMm']);
        $this->assertSame(1, substr_count(implode('', array_column($pdf['texts'], 'str')), 'Customer Signature'), 'one signature block, on the last page');
    }

    #[Test]
    public function a_one_page_invoice_keeps_its_signatures_at_the_foot(): void
    {
        $pdf = $this->inspect($this->renderPdf(true));

        $this->assertSame(1, $pdf['pages']);
        $this->assertGreaterThan(240.0, $this->find($pdf, 'Customer Signature')['baselineMm']);
        $this->assertEqualsWithDelta(288.0, max(array_column($pdf['texts'], 'baselineMm')), 3.0, 'the thank-you at the foot (297 − 7 mm)');
    }

    private function renderPdf(bool $header, ?Invoice $invoice = null): string
    {
        try {
            $bytes = app(InvoicePdf::class)->pdf(($invoice ?? $this->invoice)->fresh(), $header);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), "Executable doesn't exist")) {
                $this->markTestSkipped('No Chrome for PDF rendering: set PDF_CHROME_PATH in api/.env.');
            }
            throw $e;
        }
        $file = tempnam(sys_get_temp_dir(), 'inv').'.pdf';
        file_put_contents($file, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($file));
        // INVOICE_PDF_DUMP=/some/dir keeps the rendered PDFs for a look by eye.
        if ($dump = getenv('INVOICE_PDF_DUMP')) {
            copy($file, rtrim($dump, '/\\').'/invoice-header-'.($header ? 'on' : 'off').'.pdf');
        }

        return $file;
    }

    private function inspect(string $file, ?float $inkBottomMm = null, bool $barcode = false, int $page = 1): array
    {
        $args = [config('bhabaghure.invoices.node_binary'), config('bhabaghure.invoices.inspector'), $file, '--page', (string) $page];
        if ($inkBottomMm !== null) {
            array_push($args, '--ink-top-mm', '0', '--ink-bottom-mm', (string) $inkBottomMm);
        }
        if ($barcode) {
            $args[] = '--barcode';
        }
        $process = new Process($args, timeout: 120);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function texts(string $file): array
    {
        return array_column($this->inspect($file)['texts'], 'str');
    }

    /**
     * The text run where $needle starts. PDF text comes back in fragments (letter-spaced labels letter by letter,
     * shaped Bengali in clusters), so matching ignores whitespace and fragment boundaries.
     */
    private function find(array $pdf, string $needle): array
    {
        $needle = preg_replace('/\s+/u', '', $needle);
        $compact = '';
        $owners = [];
        foreach ($pdf['texts'] as $i => $text) {
            foreach (mb_str_split(preg_replace('/\s+/u', '', $text['str'])) as $char) {
                $compact .= $char;
                $owners[] = $i;
            }
        }
        $at = mb_strpos($compact, $needle);
        $this->assertNotFalse($at, "\"{$needle}\" not found in the PDF text");

        return $pdf['texts'][$owners[$at]];
    }

    private function assertPdfContains(string $needle, array $texts, string $message = ''): void
    {
        $this->assertStringContainsString(preg_replace('/\s+/u', '', $needle), preg_replace('/\s+/u', '', implode('', $texts)), $message);
    }
}
