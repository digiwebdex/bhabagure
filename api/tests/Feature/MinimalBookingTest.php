<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §2: a website booking needs only the lead traveller's name and WhatsApp
 * number. Passport details, date of birth and email are optional, and checked only when they are given.
 */
class MinimalBookingTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function the_lead_travellers_name_and_whatsapp_number_are_enough_to_book(): void
    {
        $booking = $this->book([
            ['name' => 'Tanvir Hasan', 'phone' => '01711-000001'],
            [],
        ]);

        $travellers = $booking->travellers->sortBy('sort_order')->values();
        $this->assertSame(['Tanvir Hasan', 'Traveller 2'], $travellers->pluck('full_name')->all());
        $this->assertSame([null, null, null], [$travellers[0]->passport_number, $travellers[0]->date_of_birth, $travellers[0]->passport_expiry]);
        $this->assertSame('8801711000001', $travellers[0]->phone);
        $customer = Customer::query()->sole();
        $this->assertSame(['Tanvir Hasan', '8801711000001', null], [$customer->name, $customer->phone, $customer->email]);

        // A Bangla booking names them in Bangla, with Bengali digits.
        $bangla = $this->book([['name' => 'তানভীর হাসান', 'phone' => '01711000002'], [], []], 'bn');
        $this->assertSame(['তানভীর হাসান', 'যাত্রী ২', 'যাত্রী ৩'], $bangla->travellers->sortBy('sort_order')->pluck('full_name')->values()->all());
    }

    #[Test]
    public function the_lead_name_and_number_are_required_and_anything_given_is_still_checked(): void
    {
        $payload = fn (array $travellers) => $this->payload($travellers) + ['expected_total' => 153000];

        $this->postJson('/api/v1/public/bookings', $payload([['phone' => '01711000001'], []]))
            ->assertUnprocessable()->assertJsonValidationErrors(['travellers.0.name']);
        $this->postJson('/api/v1/public/bookings', $payload([['name' => 'Tanvir Hasan'], []]))
            ->assertUnprocessable()->assertJsonValidationErrors(['travellers.0.phone']);
        $this->postJson('/api/v1/public/bookings', $payload([
            ['name' => 'Tanvir Hasan', 'phone' => '01711000001', 'passport_number' => '12', 'passport_expiry' => '2026-10-01', 'email' => 'not-an-email'],
            ['date_of_birth' => '2099-01-01'],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'travellers.0.passport_number', 'travellers.0.passport_expiry', 'travellers.0.email', 'travellers.1.date_of_birth',
        ]);
        $this->assertSame(0, Booking::query()->count());
    }

    #[Test]
    public function staff_complete_the_missing_details_and_the_audit_log_names_the_fields_but_not_their_values(): void
    {
        $booking = $this->book([['name' => 'Tanvir Hasan', 'phone' => '01711000001'], []]);
        [$lead, $second] = $booking->travellers->sortBy('sort_order')->values()->all();
        $admin = $this->staff('admin');

        $this->actingAsApi($admin)->putJson("/api/v1/admin/booking-travellers/{$second->id}", [
            'full_name' => ' Nusrat Jahan ', 'date_of_birth' => '1994-11-02', 'passport_number' => 'bx 4471228', 'passport_expiry' => now()->addYears(3)->toDateString(), 'phone' => '', 'email' => null,
        ])->assertOk()
            ->assertJsonPath('data.travellers.1.full_name', 'Nusrat Jahan')
            ->assertJsonPath('data.travellers.1.passport_number', 'BX4471228')
            ->assertJsonPath('data.travellers.1.date_of_birth', '1994-11-02');

        $log = DB::table('audit_logs')->where('action', 'traveller.updated')->sole();
        // MySQL stores JSON keys in its own order.
        $this->assertEquals(['traveller_id' => $second->id, 'fields' => ['full_name', 'date_of_birth', 'passport_number', 'passport_expiry']], json_decode($log->changes, true));
        $this->assertStringNotContainsString('BX4471228', (string) $log->changes);

        // Checked like the booking form; the lead keeps a WhatsApp number.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/booking-travellers/{$lead->id}", [
            'full_name' => '', 'phone' => '', 'passport_number' => '12', 'passport_expiry' => now()->toDateString(), 'date_of_birth' => '2099-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['full_name', 'phone', 'passport_number', 'passport_expiry', 'date_of_birth']);

        // A sales agent works a pool booking only after claiming it.
        $agent = $this->staff('sales_agent');
        $this->actingAsApi($agent)->putJson("/api/v1/admin/booking-travellers/{$second->id}", ['full_name' => 'Someone Else'])
            ->assertStatus(409)->assertJsonPath('code', 'claim_first');
        $this->assertSame('Nusrat Jahan', $second->fresh()->full_name);
    }

    /** @param list<array<string, string>> $travellers */
    private function book(array $travellers, string $locale = 'en'): Booking
    {
        $payload = $this->payload($travellers, $locale);
        $quote = $this->postJson('/api/v1/public/bookings', $payload + ['expected_total' => 0])->assertStatus(409)->json('quote.total');
        $reference = $this->postJson('/api/v1/public/bookings', $payload + ['expected_total' => $quote])->assertCreated()->json('data.reference');

        return Booking::query()->with('travellers')->where('reference', $reference)->firstOrFail();
    }

    /**
     * @param  list<array<string, string>>  $travellers
     * @return array<string, mixed>
     */
    private function payload(array $travellers, string $locale = 'en'): array
    {
        return [
            'package_slug' => self::MUSTANG,
            'travel_date' => now('Asia/Dhaka')->addDays(46)->toDateString(),
            'pax' => count($travellers),
            'room' => 'twin',
            'addons' => [],
            'travellers' => $travellers,
            'terms_accepted' => true,
            'locale' => $locale,
        ];
    }
}
