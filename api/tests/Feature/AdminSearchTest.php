<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\Admin\Ownership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceRecords;
use Tests\TestCase;

/** docs/phase-5-admin-core.md §4.1: the header search sees exactly what the lists show each staff member. */
class AdminSearchTest extends TestCase
{
    use CreatesFinanceRecords, RefreshDatabase;

    #[Test]
    public function search_is_scoped_like_the_lists_and_finds_phone_numbers_however_they_are_typed(): void
    {
        [$agent, $colleague] = [$this->staff('sales_agent'), $this->staff('sales_agent')];
        $customer = $this->customer(['name' => 'Farhana Akter', 'phone' => '8801711000123', 'stage' => 'customer']);
        $pool = $this->booking();
        $theirs = $this->booking();
        app(Ownership::class)->claim($theirs, $colleague);

        $found = fn ($staff, string $q) => $this->actingAsApi($staff)->getJson('/api/v1/admin/search?q='.urlencode($q))->assertOk()->json('data');

        $asAgent = $found($agent, 'Farhana');
        $this->assertSame([$pool->reference], array_column($asAgent['bookings'], 'reference'), 'the pool, not the colleague\'s booking');
        $this->assertSame([], $asAgent['customers'], 'a customer who booked through someone else is not the agent\'s');

        $asColleague = $found($colleague, '01711-000123');
        $this->assertSame([$customer->id], array_column($asColleague['customers'], 'id'));
        $this->assertContains($theirs->reference, array_column($asColleague['bookings'], 'reference'));

        $asOperator = $found($this->staff('tour_operator'), '1711000');
        $this->assertCount(2, $asOperator['bookings']);
        $this->assertSame([$customer->id], array_column($asOperator['customers'], 'id'));

        $this->assertSame([], $found($this->staff(null), 'Farhana'), 'no permission, no kinds');
        $this->actingAsApi($agent)->getJson('/api/v1/admin/search?q=F')->assertUnprocessable();
        $this->assertSame(2, Booking::query()->count());
    }
}
