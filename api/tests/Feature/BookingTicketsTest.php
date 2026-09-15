<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Destination;
use App\Services\Admin\Ownership;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two Phase 6 gaps closed (docs/phase-6-customer-portal.md §8): e-tickets recorded per traveller and shown in the
 * readiness checklist, and visa and insurance defaulting to "not required" on destinations with a visa on arrival.
 */
class BookingTicketsTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private const WITHOUT_AIR = 'kathmandu-pokhara-tour-4-nights-5-days-without-air-ticket';

    private const THAILAND = 'thailand-budget-escape-bangkok-pattaya-coral-island-with';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function staff_record_an_e_ticket_per_traveller_the_customer_sees_it_and_a_wrong_one_is_voided_not_deleted(): void
    {
        $booking = $this->book(self::MUSTANG, '01711-000001');
        [$lead, $second] = $booking->travellers->sortBy('sort_order')->values();
        $other = $this->book(self::MUSTANG, '01711-000009');
        $admin = $this->staff('admin');
        $pdf = UploadedFile::fake()->create('ticket.pdf', 120, 'application/pdf');
        $ticket = ['booking_traveller_id' => $lead->id, 'airline' => 'Biman Bangladesh', 'pnr' => 'bg7k2m', 'ticket_number' => '057 2841993', 'route' => 'DAC–KTM–DAC', 'departs_on' => $booking->travel_start->toDateString()];

        // Who may record one: an agent must own the booking; an accountant can't edit bookings.
        $this->actingAsApi($this->staff('sales_agent'))->post("/api/v1/admin/bookings/{$booking->id}/tickets", $ticket, ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('code', 'claim_first');
        $this->actingAsApi($this->staff('accountant'))->post("/api/v1/admin/bookings/{$booking->id}/tickets", $ticket, ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAsApi($admin)->post("/api/v1/admin/bookings/{$booking->id}/tickets", ['booking_traveller_id' => $other->travellers->first()->id, 'pnr' => 'x', 'ticket_number' => 'abc'] + $ticket, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['booking_traveller_id', 'pnr', 'ticket_number']);

        $data = $this->actingAsApi($admin)->post("/api/v1/admin/bookings/{$booking->id}/tickets", ['file' => $pdf] + $ticket, ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.tickets.0.pnr', 'BG7K2M')->assertJsonPath('data.tickets.0.ticketNumber', '0572841993')
            ->assertJsonPath('data.tickets.0.hasFile', true)->assertJsonPath('data.actions.manage_tickets', true)->json('data');
        $ticketId = $data['tickets'][0]['id'];
        $this->assertSame((string) file_get_contents($pdf->getRealPath()), $this->actingAsApi($admin)->get("/api/v1/admin/booking-tickets/{$ticketId}/file")->assertOk()->getContent());

        // The portal: the ticket on the trip, its PDF for this customer only; the checklist waits on the second traveller.
        $trip = $this->actingAsApi($booking->customer)->getJson("/api/v1/portal/trips/{$booking->reference}")->assertOk()->json('data');
        $this->assertSame([['Tanvir Hasan', 'BG7K2M', '0572841993']], array_map(fn ($t) => [$t['traveller'], $t['pnr'], $t['ticketNumber']], $trip['tickets']));
        $this->assertSame(['Nusrat Jahan'], collect($trip['readiness']['checks'])->firstWhere('key', 'etickets')['waitingOn']);
        $this->actingAsApi($booking->customer)->get("/api/v1/portal/tickets/{$ticketId}/file")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAsApi($other->customer)->get("/api/v1/portal/tickets/{$ticketId}/file", ['Accept' => 'application/json'])->assertNotFound();

        // Voided with a reason: gone from the portal and the checklist, kept in the admin with who and why.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/booking-tickets/{$ticketId}/void", ['reason' => ''])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/booking-tickets/{$ticketId}/void", ['reason' => 'Wrong passenger name'])->assertOk()
            ->assertJsonPath('data.tickets.0.voidReason', 'Wrong passenger name')->assertJsonPath('data.tickets.0.voidedBy', $admin->name);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/booking-tickets/{$ticketId}/void", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'ticket_voided');
        $trip = $this->actingAsApi($booking->customer)->getJson("/api/v1/portal/trips/{$booking->reference}")->assertOk()->json('data');
        $this->assertSame([], $trip['tickets']);
        $this->assertSame(['Tanvir Hasan', 'Nusrat Jahan'], collect($trip['readiness']['checks'])->firstWhere('key', 'etickets')['waitingOn']);
        $this->actingAsApi($booking->customer)->get("/api/v1/portal/tickets/{$ticketId}/file", ['Accept' => 'application/json'])->assertNotFound();

        foreach ([$lead, $second] as $i => $traveller) {
            $this->actingAsApi($admin)->post("/api/v1/admin/bookings/{$booking->id}/tickets", ['booking_traveller_id' => $traveller->id, 'ticket_number' => '057284199'.($i + 4)] + $ticket, ['Accept' => 'application/json'])->assertCreated();
        }
        $checks = $this->actingAsApi($booking->customer)->getJson("/api/v1/portal/trips/{$booking->reference}")->json('data.readiness.checks');
        $this->assertTrue(collect($checks)->firstWhere('key', 'etickets')['done']);
        $this->assertSame(['booking.ticket_issued', 'booking.ticket_voided', 'booking.ticket_issued', 'booking.ticket_issued'],
            AuditLog::query()->where('action', 'like', 'booking.ticket_%')->orderBy('id')->pluck('action')->all());
    }

    #[Test]
    public function the_e_ticket_check_shows_on_packages_with_airfare_and_once_staff_record_a_ticket_on_others(): void
    {
        $withoutAir = $this->book(self::WITHOUT_AIR, '01711-000001');
        $keys = fn (Booking $b) => array_column($this->actingAsApi($b->customer)->getJson("/api/v1/portal/trips/{$b->reference}")->assertOk()->json('data.readiness.checks'), 'key');

        $this->assertSame(['paid', 'passports', 'documents', 'visa', 'insurance'], $keys($withoutAir));

        // The customer booked their own flights, and staff recorded them anyway: now it counts.
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->post("/api/v1/admin/bookings/{$withoutAir->id}/tickets", [
            'booking_traveller_id' => $withoutAir->travellers->first()->id, 'airline' => 'US-Bangla', 'pnr' => 'UB4R7P', 'ticket_number' => '0602841993',
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertContains('etickets', $keys($withoutAir));
    }

    #[Test]
    public function visa_and_insurance_default_to_not_required_where_the_visa_is_given_on_arrival(): void
    {
        // Nepal gives the visa on arrival; Thailand needs it in advance (the client, 2026-09-15).
        $this->assertTrue(Destination::query()->where('slug', 'nepal')->value('visa_on_arrival'));
        $this->assertFalse(Destination::query()->where('slug', 'thailand')->value('visa_on_arrival'));
        $booking = $this->book(self::WITHOUT_AIR, '01711-000001');
        $admin = $this->staff('admin');
        [$lead] = $booking->travellers->sortBy('sort_order')->values();
        $slots = fn () => collect($this->actingAsApi($booking->customer)->getJson('/api/v1/portal/documents')->assertOk()->json('data.trips.0.travellers.0.documents'))
            ->mapWithKeys(fn ($slot) => [$slot['kind'] => $slot['status']])->only(['visa', 'insurance'])->all();
        $readiness = fn () => collect($this->actingAsApi($booking->customer)->getJson("/api/v1/portal/trips/{$booking->reference}")->json('data.readiness.checks'))
            ->mapWithKeys(fn ($c) => [$c['key'] => $c['done']])->only(['visa', 'insurance'])->all();

        $this->assertSame(['visa' => 'not_required', 'insurance' => 'not_required'], $slots());
        $this->assertSame(['visa' => true, 'insurance' => true], $readiness());
        $this->actingAsApi($admin)->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.visa_on_arrival', true)->assertJsonPath('data.travellers.0.documents.2.status', 'not_required');

        // Staff can still ask one traveller for insurance.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/booking-travellers/{$lead->id}/documents/insurance", ['status' => 'pending'])->assertOk()
            ->assertJsonPath('data.3.status', 'pending');
        $this->assertSame(['visa' => true, 'insurance' => false], $readiness());

        // The CMS switch: turned off for Nepal, the untouched visa waits for staff.
        $nepal = Destination::query()->where('slug', 'nepal')->firstOrFail();
        $this->actingAsApi($this->staff('tour_operator'))->putJson("/api/v1/admin/destinations/{$nepal->id}", [
            'slug' => 'nepal', 'name_bn' => $nepal->name_bn, 'name_en' => $nepal->name_en, 'country_code' => 'NP', 'region' => 'international', 'visa_on_arrival' => false,
        ])->assertOk()->assertJsonPath('data.visa_on_arrival', false);
        $this->assertSame(['visa' => 'pending', 'insurance' => 'pending'], $slots());
        $this->assertSame(['visa' => false, 'insurance' => false], $readiness());

        // Thailand: the visa is applied for in advance, so it waits for staff from the start.
        $thailand = $this->book(self::THAILAND, '01711-000002');
        $this->actingAsApi($admin)->getJson("/api/v1/admin/bookings/{$thailand->id}")->assertOk()
            ->assertJsonPath('data.visa_on_arrival', false)->assertJsonPath('data.travellers.0.documents.2.status', 'pending');
    }

    #[Test]
    public function the_thailand_migration_turns_the_visa_on_arrival_off_once_and_audits_it(): void
    {
        $thailand = Destination::query()->where('slug', 'thailand')->firstOrFail();
        $thailand->forceFill(['visa_on_arrival' => true])->save();
        $migration = require database_path('migrations/2026_09_15_150000_thailand_visa_in_advance.php');

        $migration->up();
        $migration->up();

        $this->assertFalse($thailand->fresh()->visa_on_arrival);
        $this->assertTrue(Destination::query()->where('slug', 'nepal')->value('visa_on_arrival'));
        $logs = AuditLog::query()->where('action', 'cms.destination.updated')->where('auditable_id', $thailand->id)->get();
        $this->assertCount(1, $logs);
        $this->assertEquals(['from' => true, 'to' => false], $logs->first()->changes['visa_on_arrival']);
    }

    /** A website booking for two, at whatever the package costs today. */
    private function book(string $slug, string $phone): Booking
    {
        $payload = [
            'package_slug' => $slug,
            'travel_date' => now('Asia/Dhaka')->addDays(30)->toDateString(),
            'pax' => 2,
            'room' => 'twin',
            'addons' => [],
            'travellers' => [
                ['name' => 'Tanvir Hasan', 'passport_number' => 'A01234567', 'date_of_birth' => '1990-04-12', 'passport_expiry' => '2031-01-31', 'phone' => $phone, 'email' => null],
                ['name' => 'Nusrat Jahan', 'passport_number' => 'B07654321', 'date_of_birth' => '1992-08-03', 'passport_expiry' => '2031-05-01'],
            ],
            'expected_total' => 0,
            'terms_accepted' => true,
            'locale' => 'en',
        ];
        $quote = $this->postJson('/api/v1/public/bookings', $payload)->assertStatus(409)->json('quote.total');
        $reference = $this->postJson('/api/v1/public/bookings', ['expected_total' => $quote] + $payload)->assertCreated()->json('data.reference');

        return Booking::query()->with(['travellers', 'customer'])->where('reference', $reference)->firstOrFail();
    }
}
