<?php

namespace Tests\Feature;

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
            // 2 pax twin-slab 75,000 + 12% single 9,000 pp + insurance 2,400 + pickup 800 = 171,200; 2% = 3,424.
            174624, 'bn', 'website', true,
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
        $this->assertPdfContains('১,৭৪,৬২৪', $after, 'total in Bengali digits');
        $this->assertStringNotContainsString('Renamed', implode('', $after));
    }

    #[Test]
    public function totals_and_the_status_pill_follow_the_ledger(): void
    {
        $texts = $this->texts($this->renderPdf(true));

        $this->assertPdfContains('PARTIAL', $texts);
        $this->assertPdfContains('১,০০,০০০', $texts, 'paid');
        $this->assertPdfContains('৭৪,৬২৪', $texts, 'balance due');
        $this->assertPdfContains('8FK2M4QX', $texts, 'payment reference');
        $this->assertPdfContains('Notifications: 01911000111', $texts, 'the notifications number beside the main line');
    }

    private function renderPdf(bool $header): string
    {
        try {
            $bytes = app(InvoicePdf::class)->pdf($this->invoice->fresh(), $header);
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

    private function inspect(string $file, ?float $inkBottomMm = null, bool $barcode = false): array
    {
        $args = [config('bhabaghure.invoices.node_binary'), config('bhabaghure.invoices.inspector'), $file];
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
