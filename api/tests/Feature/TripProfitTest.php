<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TripProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Finance → Trip profit (docs/trip-profit.md): hand-entered trip costing, standalone. */
class TripProfitTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/trip-profits';

    #[Test]
    public function the_api_works_out_total_cost_and_profit_whatever_the_form_sends(): void
    {
        $admin = $this->staff('admin');

        $trip = $this->actingAsApi($admin)->postJson(self::URL, [
            'title' => '  Mustang Valley Trip — October  ', 'trip_type' => 'group_tour', 'trip_date' => '2026-10-19', 'pax' => 18,
            'flight_cost' => '612000.50', 'land_package_cost' => 540000.25, 'other_cost' => 36000.1,
            'total_collected_amount' => 1404000, 'notes' => '  ',
            // Sent by a form, never taken.
            'total_cost' => 1, 'profit' => 999999999,
        ])->assertCreated()->json('data');

        $this->assertSame(['Mustang Valley Trip — October', 1188000.85, 215999.15, null], [$trip['title'], $trip['total_cost'], $trip['profit'], $trip['notes']]);
        $this->assertSame('1188000.85', TripProfit::query()->value('total_cost'));
        $this->assertTrue(AuditLog::query()->where('action', 'trip_profit.created')->exists());

        // A loss is a negative profit; a cost left empty is nothing.
        $loss = $this->actingAsApi($admin)->putJson(self::URL."/{$trip['id']}", [
            'title' => 'Mustang Valley Trip — October', 'trip_date' => '2026-10-19', 'pax' => 18,
            'flight_cost' => 700000, 'land_package_cost' => 600000, 'other_cost' => null, 'total_collected_amount' => 1250000.99,
        ])->assertOk()->json('data');
        $this->assertSame([0, 1300000, -49999.01, null], [$loss['other_cost'], $loss['total_cost'], $loss['profit'], $loss['trip_type']]);
        $this->assertSame('-49999.01', TripProfit::query()->value('profit'));
    }

    #[Test]
    public function wrong_amounts_dates_and_counts_are_refused(): void
    {
        $this->actingAsApi($this->staff('admin'))->postJson(self::URL, [
            'title' => '', 'trip_type' => 'cruise', 'trip_date' => '19/10/2026', 'pax' => 0,
            'flight_cost' => -1, 'land_package_cost' => 'a lot', 'other_cost' => 10.555, 'total_collected_amount' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'trip_type', 'trip_date', 'pax', 'flight_cost', 'land_package_cost', 'other_cost', 'total_collected_amount']);
        $this->assertSame(0, TripProfit::query()->count());
    }

    #[Test]
    public function the_list_and_its_totals_follow_the_month_year_search_and_type(): void
    {
        $this->trip('Mustang Valley Trip', '2026-10-19', 18, 1000000, 1200000, 'group_tour');
        $this->trip('Kashmir Special', '2026-10-02', 12, 800000, 760000, 'group_tour');
        $this->trip('Rahman family — Bali', '2026-09-28', 4, 300000, 345000.5, 'custom_trip');
        $this->trip('Thailand 2025', '2025-12-30', 10, 500000, 560000, null);
        $admin = $this->staff('admin');

        $october = $this->actingAsApi($admin)->getJson(self::URL.'?period=2026-10')->assertOk();
        $this->assertSame(['Mustang Valley Trip', 'Kashmir Special'], array_column($october->json('data'), 'title'), 'Newest date first.');
        $this->assertSame(['trips' => 2, 'pax' => 30, 'collected' => 1960000, 'cost' => 1800000, 'profit' => 160000], $october->json('summary'));
        $this->assertSame(['2026-10', '2026-09', '2025-12'], $october->json('months'));

        $this->assertSame(3, $this->actingAsApi($admin)->getJson(self::URL.'?period=2026')->json('summary.trips'));
        $all = $this->actingAsApi($admin)->getJson(self::URL)->json('summary');
        $this->assertSame([4, 265000.5], [$all['trips'], $all['profit']]);
        $this->assertSame(['Rahman family — Bali'], array_column($this->actingAsApi($admin)->getJson(self::URL.'?search=bali')->json('data'), 'title'));
        $this->assertSame(['Rahman family — Bali'], array_column($this->actingAsApi($admin)->getJson(self::URL.'?type=custom_trip')->json('data'), 'title'));
        // A search's % is a character, not a wildcard.
        $this->assertSame(0, $this->actingAsApi($admin)->getJson(self::URL.'?search=%25')->json('summary.trips'));
        $this->actingAsApi($admin)->getJson(self::URL.'?period=2026-13')->assertUnprocessable();

        // A deleted trip leaves the list and the totals; the audit log keeps what it was.
        $kashmir = TripProfit::query()->where('title', 'Kashmir Special')->sole();
        $this->actingAsApi($admin)->deleteJson(self::URL."/{$kashmir->id}")->assertOk();
        $this->assertSame(['trips' => 1, 'pax' => 18, 'collected' => 1200000, 'cost' => 1000000, 'profit' => 200000], $this->actingAsApi($admin)->getJson(self::URL.'?period=2026-10')->json('summary'));
        $this->assertSame('Kashmir Special', AuditLog::query()->where('action', 'trip_profit.deleted')->sole()->changes['title']);
        $this->assertSoftDeleted($kashmir);
    }

    #[Test]
    public function the_csv_has_every_filtered_trip_each_cost_and_a_totals_row(): void
    {
        $this->trip('=HYPERLINK("x")', '2026-10-19', 18, 1000000, 1200000, 'group_tour');
        $this->trip('মুস্তাং ভ্যালি', '2026-10-02', 12, 800000, 760000, null);
        $this->trip('Last year', '2025-10-02', 2, 1, 2, null);

        $response = $this->actingAsApi($this->staff('admin'))->get(self::URL.'/export?period=2026-10')->assertOk();
        $this->assertStringContainsString('bhabaghure-trip-profit-2026-10.csv', (string) $response->headers->get('Content-Disposition'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFTrip,Type,Date,Pax,", $csv);
        $lines = array_values(array_filter(explode("\n", substr($csv, 3))));
        $this->assertCount(4, $lines);
        $this->assertStringStartsWith('"\'=HYPERLINK(""x"")","group tour",2026-10-19,18,1000000.00,0.00,0.00,1000000.00,1200000.00,200000.00', $lines[1], 'A formula is text.');
        $this->assertStringContainsString('মুস্তাং ভ্যালি', $lines[2]);
        $this->assertSame('Total,,,30,1800000.00,0.00,0.00,1800000.00,1960000.00,160000.00,', $lines[3]);
        $this->assertTrue(AuditLog::query()->where('action', 'trip_profit.exported')->exists());
    }

    #[Test]
    public function only_staff_given_trip_profit_see_or_change_it(): void
    {
        $trip = $this->trip('Mustang', '2026-10-19', 18, 1000, 1200, null);
        $body = ['title' => 'x', 'trip_date' => '2026-10-19', 'pax' => 1, 'total_collected_amount' => 1];

        $this->getJson(self::URL)->assertUnauthorized();
        foreach (['accountant', 'sales_agent', 'tour_operator'] as $role) {
            $staff = $this->staff($role);
            $this->actingAsApi($staff)->getJson(self::URL)->assertForbidden();
            $this->actingAsApi($staff)->get(self::URL.'/export')->assertForbidden();
            $this->actingAsApi($staff)->postJson(self::URL, $body)->assertForbidden();
            $this->actingAsApi($staff)->deleteJson(self::URL."/{$trip->id}")->assertForbidden();
        }
        $this->actingAsApi($this->staff('super_admin'))->getJson(self::URL)->assertOk()->assertJsonPath('summary.trips', 1);

        // View alone: the list, not changes.
        $viewer = $this->staff('accountant');
        $viewer->givePermissionTo('trip_profit.view');
        $this->actingAsApi($viewer)->getJson(self::URL)->assertOk();
        $this->actingAsApi($viewer)->putJson(self::URL."/{$trip->id}", $body)->assertForbidden();
        $this->assertSame('Mustang', $trip->fresh()->title);
    }

    private function trip(string $title, string $date, int $pax, float $cost, float $collected, ?string $type): TripProfit
    {
        return TripProfit::query()->create([
            'title' => $title, 'trip_type' => $type, 'trip_date' => $date, 'pax' => $pax,
            'flight_cost' => $cost, 'land_package_cost' => 0, 'other_cost' => 0, 'total_collected_amount' => $collected,
        ]);
    }
}
