<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\TravellerDocument;
use App\Services\Documents\TravellerDocuments;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-6-customer-portal.md §3.3, §5: customers upload each traveller's passport scan and photo, encrypted on the
 * private disk and served back only to them and to staff who can see the booking; staff verify or reject; visa and
 * insurance are statuses staff set; a missing passport number can be added once and is never shown back.
 */
class PortalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function uploads_are_encrypted_served_only_to_their_customer_and_staff_verify_or_reject_them(): void
    {
        $booking = $this->websiteBooking('01711-000001');
        $other = $this->websiteBooking('01711-000009');
        $me = $booking->customer;
        [$lead] = $booking->travellers->sortBy('sort_order')->values();

        $documents = $this->actingAsApi($me)->getJson('/api/v1/portal/documents')->assertOk()->json('data');
        $this->assertSame([$booking->reference], array_column($documents['trips'], 'reference'));
        $this->assertSame(4, $documents['toDo']);
        $this->assertSame([['passport_scan', 'missing'], ['photo', 'missing'], ['visa', 'pending'], ['insurance', 'pending']],
            array_map(fn ($slot) => [$slot['kind'], $slot['status']], $documents['trips'][0]['travellers'][0]['documents']));
        $this->assertStringNotContainsString('A01234567', json_encode($documents));

        // A photo: stored encrypted, returned to its customer as sent, never cached.
        $photo = UploadedFile::fake()->image('photo.jpg', 600, 600);
        $original = (string) file_get_contents($photo->getRealPath());
        $this->actingAsApi($me)->post("/api/v1/portal/travellers/{$lead->id}/documents/photo", ['file' => $photo], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.1.status', 'uploaded');
        $row = TravellerDocument::query()->where('kind', 'photo')->firstOrFail();
        $this->assertNotSame($original, Storage::disk('local')->get($row->path));
        $file = $this->actingAsApi($me)->get("/api/v1/portal/travellers/{$lead->id}/documents/photo/file")->assertOk();
        $this->assertSame($original, $file->getContent());
        $this->assertStringContainsString('no-store', (string) $file->headers->get('Cache-Control'));

        // Wrong type, too large, someone else's traveller.
        $this->actingAsApi($me)->post("/api/v1/portal/travellers/{$lead->id}/documents/photo", ['file' => UploadedFile::fake()->create('photo.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->actingAsApi($me)->post("/api/v1/portal/travellers/{$lead->id}/documents/passport_scan", ['file' => UploadedFile::fake()->create('scan.pdf', 5200, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $theirs = $other->travellers->first();
        $this->actingAsApi($me)->post("/api/v1/portal/travellers/{$theirs->id}/documents/photo", ['file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAsApi($me)->get("/api/v1/portal/travellers/{$theirs->id}/documents/photo/file", ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAsApi($me)->post("/api/v1/portal/travellers/{$lead->id}/documents/visa", ['file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertNotFound();

        // Staff: the queue, the file, and a review — an agent must own the booking first; an accountant can only look.
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent');
        $accountant = $this->staff('accountant');
        $queue = $this->actingAsApi($accountant)->getJson('/api/v1/admin/document-reviews')->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame(['photo', 'portal', $booking->reference, false], [$queue['kind'], $queue['source'], $queue['booking']['reference'], $queue['actions']['review']]);
        $this->assertSame($original, $this->actingAsApi($accountant)->get("/api/v1/admin/traveller-documents/{$row->id}/file")->assertOk()->getContent());
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/traveller-documents/{$row->id}/review", ['decision' => 'verified'])->assertForbidden();
        $this->actingAsApi($agent)->postJson("/api/v1/admin/traveller-documents/{$row->id}/review", ['decision' => 'verified'])->assertStatus(409)->assertJsonPath('code', 'claim_first');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/traveller-documents/{$row->id}/review", ['decision' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/traveller-documents/{$row->id}/review", ['decision' => 'rejected', 'reason' => 'Face not visible — plain background please'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $slot = $this->actingAsApi($me)->getJson('/api/v1/portal/documents')->assertOk()->json('data.trips.0.travellers.0.documents.1');
        $this->assertSame(['rejected', 'Face not visible — plain background please'], [$slot['status'], $slot['note']]);

        // Uploaded again, verified, and then it stays.
        $this->actingAsApi($me)->post("/api/v1/portal/travellers/{$lead->id}/documents/photo", ['file' => UploadedFile::fake()->image('photo2.jpg', 600, 600)], ['Accept' => 'application/json'])->assertCreated();
        $this->assertFalse(Storage::disk('local')->exists($row->path), 'the rejected file is removed once replaced');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/traveller-documents/{$row->id}/review", ['decision' => 'verified'])->assertOk()->assertJsonPath('data.status', 'verified');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/traveller-documents/{$row->id}/review", ['decision' => 'verified'])->assertStatus(409)->assertJsonPath('code', 'not_waiting');
        $this->actingAsApi($me)->post("/api/v1/portal/travellers/{$lead->id}/documents/photo", ['file' => UploadedFile::fake()->image('photo3.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('code', 'already_verified');

        $this->assertSame(['traveller_document.uploaded', 'traveller_document.rejected', 'traveller_document.uploaded', 'traveller_document.verified'],
            AuditLog::query()->where('action', 'like', 'traveller_document.%')->orderBy('id')->pluck('action')->all());
        $this->actingAsApi($admin)->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.travellers.0.documents.1.status', 'verified')->assertJsonPath('data.travellers.0.documents.1.id', $row->id);
    }

    #[Test]
    public function a_missing_passport_number_can_be_added_once_and_is_never_shown_back(): void
    {
        $booking = $this->websiteBooking('01711-000001');
        $second = $booking->travellers->sortBy('sort_order')->values()[1];
        DB::table('booking_travellers')->where('id', $second->id)->update(['passport_number' => null, 'passport_number_hash' => null]);
        $me = $booking->customer;
        $this->assertSame(5, $this->actingAsApi($me)->getJson('/api/v1/portal/documents')->json('data.toDo'));

        $travelEnd = $booking->travel_end->toDateString();
        $this->actingAsApi($me)->putJson("/api/v1/portal/travellers/{$second->id}/passport", ['passport_number' => 'bx12', 'passport_expiry' => $travelEnd])
            ->assertUnprocessable()->assertJsonValidationErrors(['passport_number', 'passport_expiry']);
        $this->actingAsApi($me)->putJson("/api/v1/portal/travellers/{$second->id}/passport", ['passport_number' => 'bw 0912345', 'passport_expiry' => '2032-03-01'])
            ->assertOk()->assertExactJson(['data' => ['passportOnFile' => true]]);
        $this->assertSame('BW0912345', BookingTraveller::query()->findOrFail($second->id)->passport_number);

        $data = $this->actingAsApi($me)->getJson('/api/v1/portal/documents')->assertOk()->json('data');
        $this->assertTrue($data['trips'][0]['travellers'][1]['passportOnFile']);
        $this->assertStringNotContainsString('0912345', json_encode($data));
        $this->actingAsApi($me)->putJson("/api/v1/portal/travellers/{$second->id}/passport", ['passport_number' => 'BW7654321', 'passport_expiry' => '2032-03-01'])
            ->assertStatus(409)->assertJsonPath('code', 'passport_on_file');
        $audit = AuditLog::query()->where('action', 'traveller.passport_added')->firstOrFail();
        $this->assertStringNotContainsString('0912345', json_encode($audit->changes));
    }

    #[Test]
    public function readiness_follows_verified_documents_and_the_visa_and_insurance_staff_track(): void
    {
        $booking = $this->websiteBooking('01711-000001');
        [$lead, $second] = $booking->travellers->sortBy('sort_order')->values();
        $admin = $this->staff('admin');
        $readiness = fn () => collect($this->actingAsApi($booking->customer)->getJson("/api/v1/portal/trips/{$booking->reference}")->assertOk()->json('data.readiness.checks'))
            ->mapWithKeys(fn ($c) => [$c['key'] => $c['waitingOn']])->all();

        $this->assertSame(['paid' => [], 'passports' => [], 'documents' => ['Tanvir Hasan', 'Nusrat Jahan']], $readiness());

        foreach ([$lead, $second] as $traveller) {
            foreach (TravellerDocument::UPLOADS as $kind) {
                $this->actingAsApi($booking->customer)->post("/api/v1/portal/travellers/{$traveller->id}/documents/{$kind}", ['file' => UploadedFile::fake()->image("{$kind}.jpg")], ['Accept' => 'application/json'])->assertCreated();
            }
        }
        TravellerDocument::query()->where('booking_traveller_id', $second->id)->get()
            ->each(fn (TravellerDocument $d) => $this->actingAsApi($admin)->postJson("/api/v1/admin/traveller-documents/{$d->id}/review", ['decision' => 'verified'])->assertOk());
        $this->assertSame(['Tanvir Hasan'], $readiness()['documents']);

        // Staff start tracking the visa: the check appears and waits on whoever isn't settled yet.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/booking-travellers/{$lead->id}/documents/visa", ['status' => 'issued', 'note' => 'Nepal — visa on arrival'])
            ->assertOk()->assertJsonPath('data.2.status', 'issued');
        $this->assertSame(['Nusrat Jahan'], $readiness()['visa']);
        $this->actingAsApi($admin)->putJson("/api/v1/admin/booking-travellers/{$second->id}/documents/visa", ['status' => 'not_required'])->assertOk();
        $this->assertSame([], $readiness()['visa']);
        $this->assertArrayNotHasKey('insurance', $readiness());
        $this->actingAsApi($admin)->putJson("/api/v1/admin/booking-travellers/{$second->id}/documents/passport_scan", ['status' => 'issued'])->assertNotFound();
        $this->actingAsApi($this->staff('accountant'))->putJson("/api/v1/admin/booking-travellers/{$second->id}/documents/insurance", ['status' => 'issued'])->assertForbidden();

        $slot = $this->actingAsApi($booking->customer)->getJson('/api/v1/portal/documents')->json('data.trips.0.travellers.0.documents.2');
        $this->assertSame(['visa', 'issued', 'Nepal — visa on arrival'], [$slot['kind'], $slot['status'], $slot['note']]);
    }

    #[Test]
    public function a_scan_uploaded_with_the_website_booking_waits_for_review_like_a_portal_upload(): void
    {
        $scan = UploadedFile::fake()->image('passport.jpg', 1200, 800);
        $original = (string) file_get_contents($scan->getRealPath());
        $token = $this->post('/api/v1/public/passport-scans', ['file' => $scan], ['Accept' => 'application/json'])->assertCreated()->json('data.token');
        $payload = $this->bookingPayload('01711-000001');
        $payload['travellers'][0]['passport_scan_token'] = $token;
        $reference = $this->postJson('/api/v1/public/bookings', $payload)->assertCreated()->json('data.reference');

        $document = TravellerDocument::query()->firstOrFail();
        $this->assertSame(['passport_scan', 'uploaded', 'booking'], [$document->kind, $document->status, $document->source]);
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/document-reviews')->assertOk()->assertJsonPath('data.0.booking.reference', $reference);
        $this->assertSame($original, $this->actingAsApi($admin)->get("/api/v1/admin/traveller-documents/{$document->id}/file")->assertOk()->getContent());
        $this->assertSame($original, app(TravellerDocuments::class)->contents($document));
    }

    #[Test]
    public function the_service_works_on_a_traveller_from_a_loaded_booking_without_lazy_loading(): void
    {
        $booking = $this->websiteBooking('01711-000001');
        [$lead, $second] = $booking->travellers->sortBy('sort_order')->values();
        $documents = app(TravellerDocuments::class);

        $documents->upload($lead, TravellerDocument::PHOTO, UploadedFile::fake()->image('photo.jpg'), $booking->customer);
        $documents->setIssued($second, TravellerDocument::VISA, TravellerDocument::NOT_REQUIRED, null, $this->staff('admin'));

        $this->assertSame(2, TravellerDocument::query()->count());
        $this->assertSame([$booking->id, $booking->id], AuditLog::query()->where('action', 'like', 'traveller_document.%')->pluck('auditable_id')->all());
    }

    private function websiteBooking(string $phone): Booking
    {
        $reference = $this->postJson('/api/v1/public/bookings', $this->bookingPayload($phone))->assertCreated()->json('data.reference');

        return Booking::query()->with('travellers', 'customer')->where('reference', $reference)->firstOrFail();
    }

    private function bookingPayload(string $phone): array
    {
        return [
            'package_slug' => self::MUSTANG,
            'travel_date' => now('Asia/Dhaka')->addDays(30)->toDateString(),
            'pax' => 2,
            'room' => 'twin',
            'addons' => [],
            'travellers' => [
                ['name' => 'Tanvir Hasan', 'passport_number' => 'A01234567', 'date_of_birth' => '1990-04-12', 'passport_expiry' => '2031-01-31', 'phone' => $phone, 'email' => null],
                ['name' => 'Nusrat Jahan', 'passport_number' => 'B07654321', 'date_of_birth' => '1992-08-03', 'passport_expiry' => '2031-05-01'],
            ],
            'expected_total' => 153000,
            'terms_accepted' => true,
            'locale' => 'en',
        ];
    }
}
