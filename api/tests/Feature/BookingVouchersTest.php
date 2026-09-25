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

        // A JPG works too; anything else, a missing title, a too-large file or an unknown booking is refused.
        $this->actingAsApi($operator)->post('/api/v1/admin/vouchers', ['title' => 'Bus contract', 'file' => UploadedFile::fake()->image('contract.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAsApi($operator)->post('/api/v1/admin/vouchers', ['title' => '', 'file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])
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
