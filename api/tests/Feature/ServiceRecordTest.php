<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ServiceRecord;
use App\Models\Staff;
use App\Services\Invoices\InvoicePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/invoice-services.md: an invoice line says what it is for; a ticket or a hotel booking reaches Air ticketing or
 * Hotel requests once the invoice is issued, and its PNR, dates and status can change from either side.
 */
class ServiceRecordTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/service-records';

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Dhaka')->setDate(2026, 11, 4)->setTime(11, 0));
        $this->customer = Customer::query()->forceCreate(['name' => 'Rina Akter', 'phone' => '8801711000900', 'stage' => 'customer', 'source' => 'walk_in', 'locale' => 'en']);
    }

    private function air(array $overrides = []): array
    {
        return $overrides + [
            'type' => ServiceRecord::AIR, 'reference' => 'ab12cd', 'trip_type' => ServiceRecord::ROUND_TRIP, 'route_from' => 'Dhaka', 'route_to' => 'Bangkok',
            'starts_on' => '2026-11-08', 'ends_on' => '2026-11-15', 'status' => ServiceRecord::BOOKED,
        ];
    }

    private function hotel(array $overrides = []): array
    {
        return $overrides + [
            'type' => ServiceRecord::HOTEL, 'reference' => 'RES-778', 'hotel_name' => 'Hotel Sea View', 'room_type' => 'Deluxe twin',
            'starts_on' => '2026-11-08', 'ends_on' => '2026-11-11', 'status' => ServiceRecord::CONFIRMED,
        ];
    }

    private function body(array $lines): array
    {
        return [
            'customer_id' => $this->customer->id, 'title' => 'Bangkok trip', 'due_on' => '2026-11-04', 'discount_amount' => 0, 'delivery_charge' => 0, 'vat_rate' => 0,
            'lines' => array_map(fn (array $line) => $line + ['quantity' => 1, 'unit_price' => 45000, 'discount_amount' => 0, 'vat_rate' => 0], $lines),
        ];
    }

    private function draft(array $lines, ?Staff $staff = null): array
    {
        return $this->actingAsApi($staff ?? $this->staff('admin'))->postJson('/api/v1/admin/invoices', $this->body($lines))->assertCreated()->json('data');
    }

    private function issued(array $lines, ?Staff $staff = null): array
    {
        $staff ??= $this->staff('admin');
        $draft = $this->draft($lines, $staff);

        return $this->actingAsApi($staff)->postJson("/api/v1/admin/invoices/{$draft['id']}/issue")->assertOk()->json('data');
    }

    private function list(string $kind, string $query = '', ?Staff $staff = null): array
    {
        return $this->actingAsApi($staff ?? $this->staff('admin'))->getJson(self::URL."?kind={$kind}{$query}")->assertOk()->json();
    }

    #[Test]
    public function an_issued_invoice_sends_each_line_to_its_own_list_and_a_draft_sends_nothing(): void
    {
        $admin = $this->staff('admin');
        $draft = $this->draft([
            ['title' => 'Air ticket', 'service' => $this->air()],
            ['title' => 'Hotel', 'service' => $this->hotel()],
            ['title' => 'Mustang tour', 'service' => ['type' => ServiceRecord::TOUR, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-07']],
            ['title' => 'Visa fee'],
        ], $admin);

        // Not sold yet.
        $this->assertSame([], $this->list('air', staff: $admin)['data']);
        $this->assertSame([], $this->list('hotel', staff: $admin)['data']);
        // On the draft itself: the PNR in capitals, the tour named after its line, a line that is for nothing else untouched.
        $this->assertSame('AB12CD', $draft['lines'][0]['service']['reference']);
        $this->assertSame('Mustang tour', $draft['lines'][2]['service']['package_name']);
        $this->assertNull($draft['lines'][2]['service']['status']);
        $this->assertNull($draft['lines'][3]['service']);

        $this->actingAsApi($admin)->postJson("/api/v1/admin/invoices/{$draft['id']}/issue")->assertOk();

        $air = $this->list('air', staff: $admin)['data'];
        $this->assertCount(1, $air);
        $this->assertSame(
            ['Rina Akter', '8801711000900', 'AB12CD', 'Dhaka', 'Bangkok', '2026-11-08', '2026-11-15', 'booked'],
            [$air[0]['customer_name'], $air[0]['phone'], $air[0]['reference'], $air[0]['route_from'], $air[0]['route_to'], $air[0]['starts_on'], $air[0]['ends_on'], $air[0]['status']],
        );
        $this->assertNotNull($air[0]['invoice']['number']);
        $this->assertArrayNotHasKey('unit_price', $air[0], 'Prices stay on the invoice.');

        $hotel = $this->list('hotel', staff: $admin)['data'];
        $this->assertSame(['Hotel Sea View', 'RES-778', 'confirmed'], [$hotel[0]['hotel_name'], $hotel[0]['reference'], $hotel[0]['status']]);
    }

    #[Test]
    public function each_kind_needs_its_own_details(): void
    {
        $admin = $this->staff('admin');
        $send = fn (array $service) => $this->actingAsApi($admin)->postJson('/api/v1/admin/invoices', $this->body([['title' => 'Line', 'service' => $service]]));

        $send(['type' => ServiceRecord::AIR])->assertUnprocessable()->assertJsonValidationErrors([
            'lines.0.service.trip_type', 'lines.0.service.route_from', 'lines.0.service.route_to', 'lines.0.service.starts_on',
        ]);
        $send($this->air(['ends_on' => null]))->assertUnprocessable()->assertJsonValidationErrors(['lines.0.service.ends_on' => 'Choose the return or check-out date.']);
        $send($this->air(['ends_on' => '2026-11-01']))->assertUnprocessable()->assertJsonValidationErrors(['lines.0.service.ends_on']);
        $send(['type' => ServiceRecord::HOTEL])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.service.hotel_name', 'lines.0.service.starts_on', 'lines.0.service.ends_on']);
        $send(['type' => 'cruise'])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.service.type']);

        // A PNR comes later; a one-way ticket has no return, whatever the form still held; a tour needs nothing more.
        $oneWay = $send($this->air(['reference' => null, 'trip_type' => ServiceRecord::ONE_WAY, 'ends_on' => '2026-11-15']))->assertCreated()->json('data.lines.0.service');
        $this->assertSame([null, null], [$oneWay['reference'], $oneWay['ends_on']]);
        $send(['type' => ServiceRecord::TOUR])->assertCreated();
        // A line for nothing in particular needs nothing.
        $this->actingAsApi($admin)->postJson('/api/v1/admin/invoices', $this->body([['title' => 'Visa fee', 'service' => null]]))->assertCreated();
    }

    #[Test]
    public function a_correction_keeps_each_record_and_what_has_happened_to_it(): void
    {
        $admin = $this->staff('admin');
        $invoice = $this->issued([['title' => 'Air ticket', 'service' => $this->air()], ['title' => 'Hotel', 'service' => $this->hotel()]], $admin);
        $air = $invoice['lines'][0]['service'];
        $hotel = $invoice['lines'][1]['service'];

        $this->actingAsApi($admin)->putJson(self::URL."/{$air['id']}", ['status' => 'schedule_changed', 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-15', 'schedule_note' => 'Now leaves 22:40', 'reference' => 'AB12CD'])->assertOk();

        // The price corrected and the route put right, the form sending what it read; the hotel line taken off.
        $body = $this->body([['title' => 'Air ticket', 'unit_price' => 50000, 'service' => $this->air(['line_key' => $air['line_key'], 'route_to' => 'Phuket', 'status' => 'schedule_changed'])]]);
        $corrected = $this->actingAsApi($admin)->putJson("/api/v1/admin/invoices/{$invoice['id']}", $body)->assertOk()->json('data');

        $this->assertSame($air['id'], $corrected['lines'][0]['service']['id'], 'The same record, not a new one.');
        $row = $this->list('air', staff: $admin)['data'];
        $this->assertCount(1, $row);
        $this->assertSame(['Phuket', 'schedule_changed', 'Now leaves 22:40'], [$row[0]['route_to'], $row[0]['status'], $row[0]['schedule_note']]);
        $this->assertSame([], $this->list('hotel', staff: $admin)['data'], 'A line taken off takes its record.');
        $this->assertSame($hotel['hotel_name'] ?? null, AuditLog::query()->where('action', 'service_record.removed')->sole()->changes['hotel_name']);

        // Changed to something else, it leaves the old list and starts afresh in the new one.
        $body = $this->body([['title' => 'Hotel instead', 'service' => $this->hotel(['line_key' => $air['line_key'], 'status' => null])]]);
        $this->actingAsApi($admin)->putJson("/api/v1/admin/invoices/{$invoice['id']}", $body)->assertOk();
        $this->assertSame([], $this->list('air', staff: $admin)['data']);
        $row = $this->list('hotel', staff: $admin)['data'][0];
        $this->assertSame([$air['id'], 'booked', null, null], [$row['id'], $row['status'], $row['schedule_note'], $row['route_from']]);
    }

    #[Test]
    public function a_cancelled_invoice_takes_its_record_off_the_main_list(): void
    {
        $admin = $this->staff('admin');
        $invoice = $this->issued([['title' => 'Air ticket', 'service' => $this->air()]], $admin);
        $id = $invoice['lines'][0]['service']['id'];

        $this->actingAsApi($admin)->postJson("/api/v1/admin/deals/{$invoice['id']}/void", ['reason' => 'Customer cancelled'])->assertOk();

        $this->assertSame([], $this->list('air', staff: $admin)['data']);
        $cancelled = $this->list('air', '&filter=cancelled', $admin)['data'];
        $this->assertSame([true, false, false], [$cancelled[0]['cancelled'], $cancelled[0]['can_update'], $cancelled[0]['departure_soon']]);
        $this->assertCount(1, $this->list('air', '&filter=all', $admin)['data']);
        $this->actingAsApi($admin)->putJson(self::URL."/{$id}", ['status' => 'confirmed', 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-15'])
            ->assertStatus(409)->assertJsonPath('code', 'invoice_cancelled');
    }

    #[Test]
    public function departure_soon_shows_from_seven_days_and_turns_urgent_at_three(): void
    {
        $admin = $this->staff('admin');
        $this->issued([
            ['title' => 'Far', 'service' => $this->air(['reference' => 'FAR111', 'starts_on' => '2026-11-12', 'ends_on' => '2026-11-20'])],
            ['title' => 'Week', 'service' => $this->air(['reference' => 'WEEK11', 'starts_on' => '2026-11-11', 'ends_on' => '2026-11-20'])],
            ['title' => 'Soon', 'service' => $this->air(['reference' => 'SOON11', 'starts_on' => '2026-11-07', 'ends_on' => '2026-11-20'])],
            ['title' => 'Today', 'service' => $this->air(['reference' => 'TODAY1', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-20'])],
            ['title' => 'Gone', 'service' => $this->air(['reference' => 'GONE11', 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-20'])],
        ], $admin);

        $list = $this->list('air', staff: $admin);
        // Still to come, the next departure first.
        $this->assertSame(['TODAY1', 'SOON11', 'WEEK11', 'FAR111'], array_column($list['data'], 'reference'));
        $this->assertSame([0, 3, 7, 8], array_column($list['data'], 'days_left'));
        $this->assertSame([true, true, true, false], array_column($list['data'], 'departure_soon'));
        $this->assertSame([true, true, false, false], array_column($list['data'], 'departure_urgent'));
        $this->assertSame(3, $list['meta']['counts']['soon']);

        $this->assertSame(['TODAY1', 'SOON11', 'WEEK11'], array_column($this->list('air', '&filter=soon', $admin)['data'], 'reference'));
        $past = $this->list('air', '&filter=past', $admin)['data'];
        $this->assertSame(['GONE11', false], [$past[0]['reference'], $past[0]['departure_soon']]);

        // A day later in Dhaka, even just after midnight there while it is still yesterday in UTC.
        $this->travelTo(now('Asia/Dhaka')->setDate(2026, 11, 5)->setTime(0, 30));
        $this->assertSame(['SOON11', 'WEEK11', 'FAR111'], array_column($this->list('air', staff: $admin)['data'], 'reference'));
        $this->assertSame([2, 6, 7], array_column($this->list('air', staff: $admin)['data'], 'days_left'));
    }

    #[Test]
    public function a_schedule_change_needs_its_note_and_the_whatsapp_message_tells_it(): void
    {
        $admin = $this->staff('admin');
        $invoice = $this->issued([['title' => 'Air ticket', 'service' => $this->air()]], $admin);
        $id = $invoice['lines'][0]['service']['id'];
        $url = self::URL."/{$id}";

        // Before: what the customer needs to know about the ticket.
        $before = $this->list('air', staff: $admin)['data'][0]['whatsapp_text'];
        $this->assertStringContainsString('Dear Rina Akter,', $before);
        $this->assertStringContainsString('Here are your flight details.', $before);
        $this->assertStringContainsString('PNR: AB12CD', $before);
        $this->assertStringContainsString('Departure: 8 November 2026', $before);
        $this->assertStringContainsString('Status: Booked', $before);

        $this->actingAsApi($admin)->putJson($url, ['status' => 'schedule_changed', 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-15', 'schedule_note' => '  '])
            ->assertUnprocessable()->assertJsonValidationErrors(['schedule_note' => 'Write what the new schedule is.']);

        $row = $this->actingAsApi($admin)->putJson($url, ['status' => 'schedule_changed', 'reference' => 'xy99zz', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-15', 'schedule_note' => 'BG 388 now leaves 9 Nov at 22:40'])
            ->assertOk()->json('data');
        $this->assertSame(['schedule_changed', 'XY99ZZ', '2026-11-09', 'BG 388 now leaves 9 Nov at 22:40'], [$row['status'], $row['reference'], $row['starts_on'], $row['schedule_note']]);
        $this->assertNotNull($row['schedule_changed_at']);
        $this->assertSame($admin->name, $row['updated_by']);
        $this->assertStringContainsString('The schedule of your flight has changed.', $row['whatsapp_text']);
        $this->assertStringContainsString('PNR: XY99ZZ', $row['whatsapp_text']);
        $this->assertStringContainsString('Route: Dhaka → Bangkok', $row['whatsapp_text']);
        $this->assertStringContainsString('New schedule: BG 388 now leaves 9 Nov at 22:40', $row['whatsapp_text']);
        $this->assertStringContainsString('Thank you, ', $row['whatsapp_text']);
        $this->assertSame('schedule_changed', AuditLog::query()->where('action', 'service_record.updated')->sole()->changes['after']['status']);
        $this->assertCount(1, $this->list('air', '&filter=changed', $admin)['data']);

        // Put right again, the note goes with the status.
        $row = $this->actingAsApi($admin)->putJson($url, ['status' => 'confirmed', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-15'])->assertOk()->json('data');
        $this->assertSame(['confirmed', null, null], [$row['status'], $row['schedule_note'], $row['schedule_changed_at']]);

        // The customer's language, Bangla when it isn't known.
        $this->customer->forceFill(['locale' => 'bn'])->save();
        $bangla = $this->list('air', staff: $admin)['data'][0]['whatsapp_text'];
        $this->assertStringContainsString('প্রিয় Rina Akter,', $bangla);
        $this->assertStringContainsString('যাত্রা: ৯ নভেম্বর ২০২৬', $bangla);
        $this->assertStringContainsString('অবস্থা: কনফার্মড', $bangla);

        // A hotel has no schedule to change.
        $hotel = $this->issued([['title' => 'Hotel', 'service' => $this->hotel()]], $admin)['lines'][0]['service'];
        $this->actingAsApi($admin)->putJson(self::URL."/{$hotel['id']}", ['status' => 'schedule_changed', 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-11'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertStringContainsString('হোটেল: Hotel Sea View', $this->list('hotel', staff: $admin)['data'][0]['whatsapp_text']);
    }

    #[Test]
    public function the_lists_search_by_passenger_pnr_route_and_invoice(): void
    {
        $admin = $this->staff('admin');
        $invoice = $this->issued([
            ['title' => 'A', 'service' => $this->air(['reference' => 'AAA111', 'passenger_name' => 'Karim Hasan', 'route_to' => 'Kathmandu'])],
            ['title' => 'B', 'service' => $this->air(['reference' => 'BBB222'])],
        ], $admin);
        $found = fn (string $search) => array_column($this->list('air', '&search='.urlencode($search), $admin)['data'], 'reference');

        $this->assertSame(['AAA111'], $found('aaa111'));
        $this->assertSame(['AAA111'], $found('Karim'));
        $this->assertSame(['AAA111'], $found('kathmandu'));
        $this->assertCount(2, $found('Rina'));
        $this->assertCount(2, $found('01711000900') + $found('8801711000900'));
        $this->assertCount(2, $found((string) $invoice['number']));
        $this->assertSame([], $found('%'));
    }

    #[Test]
    public function only_staff_of_that_list_or_of_invoices_may_see_or_change_it(): void
    {
        $this->getJson(self::URL.'?kind=air')->assertUnauthorized();

        $admin = $this->staff('admin');
        $id = $this->issued([['title' => 'Air ticket', 'service' => $this->air()]], $admin)['lines'][0]['service']['id'];
        $body = ['status' => 'confirmed', 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-15'];

        // The seeded accountant works invoices, not the air queue: changes the status from the invoice, doesn't read the list.
        $accountant = $this->staff('accountant');
        $this->assertFalse($accountant->can('air_inquiries.view'));
        $this->actingAsApi($accountant)->getJson(self::URL.'?kind=air')->assertForbidden();
        $this->actingAsApi($accountant)->putJson(self::URL."/{$id}", $body)->assertOk()->assertJsonPath('data.status', 'confirmed');

        // A sales agent works the air queue.
        $agent = $this->staff('sales_agent');
        $this->actingAsApi($agent)->getJson(self::URL.'?kind=air')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($agent)->putJson(self::URL."/{$id}", $body + ['reference' => 'NEW111'])->assertOk()->assertJsonPath('data.reference', 'NEW111');

        // Someone who works neither.
        $this->actingAsApi($this->staff('tour_operator'))->getJson(self::URL.'?kind=air')->assertForbidden();

        // A tour package has no list, and isn't changed through one.
        $tour = $this->issued([['title' => 'Tour', 'service' => ['type' => ServiceRecord::TOUR]]], $admin)['lines'][0]['service'];
        $this->actingAsApi($admin)->putJson(self::URL."/{$tour['id']}", $body)->assertNotFound();
        $this->actingAsApi($admin)->getJson(self::URL.'?kind=tour')->assertUnprocessable();
    }

    #[Test]
    public function the_printed_invoice_carries_the_details_and_is_made_again_when_they_change(): void
    {
        Storage::fake('local');
        $admin = $this->staff('admin');
        $data = $this->issued([
            ['title' => 'Air ticket', 'service' => $this->air(['passenger_name' => 'Karim Hasan'])],
            ['title' => 'Hotel', 'service' => $this->hotel()],
            ['title' => 'Mustang tour', 'service' => ['type' => ServiceRecord::TOUR, 'package_name' => 'Mustang Valley', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-07']],
            ['title' => 'Visa fee'],
        ], $admin);
        $invoice = Invoice::query()->findOrFail($data['id']);
        $pdf = app(InvoicePdf::class);

        foreach (['a4', 'slip'] as $size) {
            $html = html_entity_decode($pdf->html($invoice, header: true, locale: 'en', size: $size));
            $this->assertStringContainsString('Passenger Karim Hasan · PNR AB12CD · Dhaka → Bangkok · Round trip · Departure 8 November 2026 · Return 15 November 2026', $html, $size);
            $this->assertStringContainsString('Hotel Sea View · Deluxe twin · Reservation RES-778 · Check-in 8 November 2026 · Check-out 11 November 2026', $html, $size);
            $this->assertStringContainsString('Mustang Valley · Departure 1 December 2026 · Return 7 December 2026', $html, $size);
            $this->assertStringNotContainsString('Booked', $html, 'The status stays off the customer\'s copy.');
        }

        // A PNR that came through after issue reaches the next copy.
        $air = $data['lines'][0]['service'];
        $this->actingAsApi($admin)->putJson(self::URL."/{$air['id']}", ['status' => 'confirmed', 'reference' => 'NEWPNR', 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-15'])->assertOk();
        $this->assertStringContainsString('PNR NEWPNR', html_entity_decode($pdf->html($invoice->fresh(), header: true, locale: 'en')));
    }
}
