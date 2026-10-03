<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingVoucher;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/booking-vouchers.md: suppliers' confirmation vouchers and contracts, on their own screen and on the booking. */
class BookingVouchersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function operations_upload_a_pdf_or_jpg_voucher_which_is_stored_encrypted_and_opens_or_downloads_as_uploaded(): void
    {
        $operator = $this->staff('tour_operator');
        $booking = $this->book();
        $pdf = UploadedFile::fake()->createWithContent('Hotel Himalaya voucher.pdf', "%PDF-1.4\nvoucher body\n%%EOF");

        $voucher = $this->actingAsApi($operator)->post('/api/v1/admin/vouchers', [
            'title' => 'Hotel Himalaya — Kathmandu, 3 rooms', 'booking_reference' => strtolower($booking->reference),
            'service_date' => now('Asia/Dhaka')->addDays(30)->toDateString(), 'file' => $pdf,
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.booking.reference', $booking->reference)->assertJsonPath('data.mime', 'application/pdf')
            ->assertJsonPath('data.original_name', 'Hotel Himalaya voucher.pdf')->json('data');

        // Encrypted on the private disk: the stored bytes are not the file.
        $row = BookingVoucher::query()->sole();
        $this->assertStringStartsWith('booking-vouchers/', $row->path);
        $this->assertStringNotContainsString('voucher body', (string) Storage::disk($row->disk)->get($row->path));

        // Opened in the browser, or downloaded under its own name.
        $open = $this->actingAsApi($operator)->get("/api/v1/admin/vouchers/{$voucher['id']}/file")->assertOk();
        $this->assertSame("%PDF-1.4\nvoucher body\n%%EOF", $open->getContent());
        $this->assertStringStartsWith('inline;', (string) $open->headers->get('Content-Disposition'));
        $download = $this->actingAsApi($operator)->get("/api/v1/admin/vouchers/{$voucher['id']}/file?download=1")->assertOk();
        $this->assertSame('attachment; filename="Hotel Himalaya voucher.pdf"', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));

        // A JPG or a PNG (2026-10-03) works too; anything else, a missing title, a too-large file or an unknown booking is refused.
        $this->actingAsApi($operator)->post('/api/v1/admin/vouchers', ['title' => 'Bus contract', 'file' => UploadedFile::fake()->image('contract.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAsApi($operator)->post('/api/v1/admin/vouchers', ['title' => '', 'file' => UploadedFile::fake()->image('a.gif')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'file']);
        $this->actingAsApi($operator)->post('/api/v1/admin/vouchers', ['title' => 'Big', 'file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAsApi($operator)->post('/api/v1/admin/vouchers', ['title' => 'X', 'booking_reference' => 'BH-0000-999', 'file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['booking_reference' => 'No booking has that number.']);

        // The booking's page lists it.
        $this->actingAsApi($this->staff('admin'))->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.vouchers.0.title', 'Hotel Himalaya — Kathmandu, 3 rooms')->assertJsonPath('data.actions.upload_voucher', true);
        $this->assertSame(['voucher.uploaded', 'voucher.uploaded'], AuditLog::query()->where('auditable_type', 'booking_voucher')->pluck('action')->all());
    }

    #[Test]
    public function the_list_shows_upcoming_first_then_past_and_archived_ones_apart_and_can_be_searched(): void
    {
        $admin = $this->staff('admin');
        $today = now('Asia/Dhaka');
        $upload = fn (string $title, ?string $date) => $this->actingAsApi($admin)->post('/api/v1/admin/vouchers', array_filter([
            'title' => $title, 'service_date' => $date, 'file' => UploadedFile::fake()->image(Str::slug($title).'.jpg'),
        ]), ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $upload('Later hotel', $today->copy()->addDays(20)->toDateString());
        $upload('No date contract', null);
        $upload('Soon transport', $today->copy()->addDays(2)->toDateString());
        $upload('Past hotel', $today->copy()->subDays(3)->toDateString());
        $old = $upload('Wrong upload', $today->copy()->addDays(5)->toDateString());

        $this->actingAsApi($admin)->postJson("/api/v1/admin/vouchers/{$old}/archive", ['reason' => 'x'])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/vouchers/{$old}/archive", ['reason' => 'Uploaded twice'])->assertOk()
            ->assertJsonPath('data.archive_reason', 'Uploaded twice')->assertJsonPath('data.archived_by', $admin->name);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/vouchers/{$old}/archive", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'voucher_archived');

        $titles = fn (string $query) => array_column($this->actingAsApi($admin)->getJson("/api/v1/admin/vouchers{$query}")->assertOk()->json('data'), 'title');
        $this->assertSame(['Soon transport', 'Later hotel', 'No date contract'], $titles(''));
        $this->assertSame(['Past hotel'], $titles('?view=past'));
        $this->assertSame(['Wrong upload'], $titles('?view=archived'));
        $this->assertSame(['Later hotel'], $titles('?search=later'));
        $this->actingAsApi($admin)->getJson('/api/v1/admin/vouchers')->assertJsonPath('meta.counts', ['upcoming' => 3, 'past' => 1, 'archived' => 1]);
        // Archived ones still open: nothing is lost.
        $this->actingAsApi($admin)->get("/api/v1/admin/vouchers/{$old}/file")->assertOk();
    }

    #[Test]
    public function sales_and_accounts_read_vouchers_operations_change_them_and_nobody_else_sees_them(): void
    {
        $id = $this->actingAsApi($this->staff('admin'))->post('/api/v1/admin/vouchers', ['title' => 'Hotel', 'file' => UploadedFile::fake()->image('h.jpg')], ['Accept' => 'application/json'])->json('data.id');

        foreach (['sales_agent', 'accountant'] as $role) {
            $staff = $this->staff($role);
            $this->actingAsApi($staff)->getJson('/api/v1/admin/vouchers')->assertOk()->assertJsonCount(1, 'data');
            $this->actingAsApi($staff)->get("/api/v1/admin/vouchers/{$id}/file?download=1")->assertOk();
            $this->actingAsApi($staff)->post('/api/v1/admin/vouchers', ['title' => 'X', 'file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertForbidden();
            $this->actingAsApi($staff)->postJson("/api/v1/admin/vouchers/{$id}/archive", ['reason' => 'Not mine'])->assertForbidden();
        }
    }

    #[Test]
    public function a_voucher_is_edited_and_a_replaced_file_is_kept_as_an_earlier_file_that_still_opens(): void
    {
        $operator = $this->staff('tour_operator');
        $admin = $this->staff('admin');
        $booking = $this->book();
        $headers = ['Accept' => 'application/json'];
        $id = $this->actingAsApi($admin)->post('/api/v1/admin/vouchers', [
            'title' => 'Thailand trip hotel', 'file' => UploadedFile::fake()->createWithContent('Untitled design (1).pdf', "%PDF-1.4\nfirst\n%%EOF"),
        ], $headers)->assertCreated()->json('data.id');
        $firstPath = BookingVoucher::query()->findOrFail($id)->path;

        // Title, booking and date, the file left as it is: no earlier file.
        $date = now('Asia/Dhaka')->addDays(12)->toDateString();
        $this->actingAsApi($operator)->post("/api/v1/admin/vouchers/{$id}", [
            '_method' => 'PUT', 'title' => 'Thailand Trip Junaidul Haq Siddique 05 - 18 October', 'booking_reference' => strtolower($booking->reference), 'service_date' => $date,
        ], $headers)->assertOk()
            ->assertJsonPath('data.title', 'Thailand Trip Junaidul Haq Siddique 05 - 18 October')->assertJsonPath('data.booking.reference', $booking->reference)
            ->assertJsonPath('data.service_date', $date)->assertJsonPath('data.original_name', 'Untitled design (1).pdf')->assertJsonPath('data.earlier_files', []);

        // A new file (a PNG): it is the voucher's file now; the first one is kept, still encrypted, and still opens.
        $png = UploadedFile::fake()->image('Revised voucher.png');
        $edited = $this->actingAsApi($operator)->post("/api/v1/admin/vouchers/{$id}", [
            '_method' => 'PUT', 'title' => 'Thailand Trip Junaidul Haq Siddique 05 - 18 October', 'booking_reference' => $booking->reference, 'service_date' => $date, 'file' => $png,
        ], $headers)->assertOk()
            ->assertJsonPath('data.original_name', 'Revised voucher.png')->assertJsonPath('data.mime', 'image/png')
            ->assertJsonPath('data.earlier_files.0.original_name', 'Untitled design (1).pdf')
            ->assertJsonPath('data.earlier_files.0.uploaded_by', $admin->name)->assertJsonPath('data.earlier_files.0.replaced_by', $operator->name)->json('data');
        $this->assertNotSame($firstPath, BookingVoucher::query()->findOrFail($id)->path);
        Storage::disk('local')->assertExists($firstPath);
        $this->assertSame((string) file_get_contents($png->getRealPath()), $this->actingAsApi($operator)->get("/api/v1/admin/vouchers/{$id}/file")->assertOk()->getContent());
        $earlier = $edited['earlier_files'][0]['id'];
        $this->assertSame("%PDF-1.4\nfirst\n%%EOF", $this->actingAsApi($this->staff('sales_agent'))->get("/api/v1/admin/vouchers/{$id}/earlier-files/{$earlier}")->assertOk()->getContent());
        $this->assertSame('attachment; filename="Untitled design (1).pdf"', $this->actingAsApi($operator)->get("/api/v1/admin/vouchers/{$id}/earlier-files/{$earlier}?download=1")->headers->get('Content-Disposition'));
        // Another voucher's number doesn't open it.
        $other = $this->actingAsApi($admin)->post('/api/v1/admin/vouchers', ['title' => 'Other', 'file' => UploadedFile::fake()->image('o.jpg')], $headers)->json('data.id');
        $this->actingAsApi($operator)->get("/api/v1/admin/vouchers/{$other}/earlier-files/{$earlier}", $headers)->assertNotFound();

        // Unlinking the booking and clearing the date; a missing title, an unknown booking or a wrong file is refused.
        $this->actingAsApi($operator)->post("/api/v1/admin/vouchers/{$id}", ['_method' => 'PUT', 'title' => 'Thailand hotel', 'booking_reference' => '', 'service_date' => ''], $headers)
            ->assertOk()->assertJsonPath('data.booking', null)->assertJsonPath('data.service_date', null)->assertJsonCount(1, 'data.earlier_files');
        $this->actingAsApi($operator)->post("/api/v1/admin/vouchers/{$id}", ['_method' => 'PUT', 'title' => '', 'file' => UploadedFile::fake()->image('x.gif')], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'file']);
        $this->actingAsApi($operator)->post("/api/v1/admin/vouchers/{$id}", ['_method' => 'PUT', 'title' => 'Thailand hotel', 'booking_reference' => 'BH-0000-999'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['booking_reference' => 'No booking has that number.']);

        $this->assertSame(['voucher.uploaded', 'voucher.updated', 'voucher.updated', 'voucher.updated'], AuditLog::query()->where('auditable_type', 'booking_voucher')->where('auditable_id', $id)->orderBy('id')->pluck('action')->all());
        $this->assertEquals(['from' => 'Untitled design (1).pdf', 'to' => 'Revised voucher.png'], AuditLog::query()->where('action', 'voucher.updated')->orderBy('id')->skip(1)->first()->changes['file']);

        // Archived: no more edits. Sales and accounts may not edit.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/vouchers/{$id}/archive", ['reason' => 'Trip moved'])->assertOk();
        $this->actingAsApi($operator)->post("/api/v1/admin/vouchers/{$id}", ['_method' => 'PUT', 'title' => 'Again'], $headers)->assertStatus(409)->assertJsonPath('code', 'voucher_archived');
        $this->actingAsApi($this->staff('sales_agent'))->post("/api/v1/admin/vouchers/{$other}", ['_method' => 'PUT', 'title' => 'Mine now'], $headers)->assertForbidden();
    }

    #[Test]
    public function the_booking_box_finds_bookings_by_number_or_customer(): void
    {
        $booking = $this->book();

        $this->actingAsApi($this->staff('tour_operator'))->getJson('/api/v1/admin/vouchers/bookings?search=tanvir')->assertOk()
            ->assertJsonPath('data.0.reference', $booking->reference)->assertJsonPath('data.0.customer', 'Tanvir Hasan')
            ->assertJsonPath('data.0.travel_start', $booking->travel_start->toDateString());
        $this->actingAsApi($this->staff('tour_operator'))->getJson('/api/v1/admin/vouchers/bookings?search='.substr($booking->reference, -3))->assertOk()->assertJsonPath('data.0.id', $booking->id);
        $this->actingAsApi($this->staff('tour_operator'))->getJson('/api/v1/admin/vouchers/bookings?search=nobody-like-this')->assertOk()->assertJsonCount(0, 'data');
        // A click on the empty box: the newest bookings, with or without an empty search.
        $this->actingAsApi($this->staff('tour_operator'))->getJson('/api/v1/admin/vouchers/bookings')->assertOk()->assertJsonPath('data.0.reference', $booking->reference);
        $this->actingAsApi($this->staff('tour_operator'))->getJson('/api/v1/admin/vouchers/bookings?search=')->assertOk()->assertJsonPath('data.0.reference', $booking->reference);
        $this->actingAsApi($this->staff('sales_agent'))->getJson('/api/v1/admin/vouchers/bookings')->assertForbidden();
    }

    #[Test]
    public function nobody_signed_out_lists_or_opens_a_voucher(): void
    {
        $voucher = BookingVoucher::query()->create([
            'title' => 'Hotel', 'disk' => 'local', 'path' => 'booking-vouchers/x.enc', 'mime' => 'image/jpeg', 'bytes' => 10, 'original_name' => 'h.jpg',
        ]);

        $this->getJson('/api/v1/admin/vouchers')->assertUnauthorized();
        $this->get("/api/v1/admin/vouchers/{$voucher->id}/file", ['Accept' => 'application/json'])->assertUnauthorized();
        $this->get("/api/v1/admin/vouchers/{$voucher->id}/file?download=1", ['Accept' => 'application/json'])->assertUnauthorized();
    }

    private function book(): Booking
    {
        $reference = $this->postJson('/api/v1/public/bookings', [
            'package_slug' => 'nepal-mustang-adventure-tour-8-days-7-nights', 'travel_date' => now('Asia/Dhaka')->addDays(30)->toDateString(),
            'pax' => 2, 'room' => 'twin', 'addons' => [], 'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711-000321'], []],
            'expected_total' => 153000, 'terms_accepted' => true, 'locale' => 'en',
        ])->assertCreated()->json('data.reference');

        return Booking::query()->where('reference', $reference)->sole();
    }
}
