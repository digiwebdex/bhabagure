<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-9-accounts.md §9: customers as the books see them — how many invoices, for how much, and what is still
 * owed — and one customer's invoices with the payments against each.
 */
class CustomerAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Staff $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountant = $this->staff('accountant');
        Storage::fake('local');
    }

    private function person(string $name, string $phone, array $attributes = []): Customer
    {
        return Customer::query()->forceCreate($attributes + ['name' => $name, 'phone' => $phone, 'stage' => 'customer', 'source' => 'walk_in']);
    }

    /** An invoice for one line at this price; issued unless asked not to be. */
    private function invoice(Customer $customer, float $price, bool $issue = true): int
    {
        $id = $this->actingAsApi($this->accountant)->postJson('/api/v1/admin/invoices', [
            'customer_id' => $customer->id, 'title' => 'Tour', 'lines' => [['title' => 'Tour', 'quantity' => 1, 'unit_price' => $price]],
        ])->assertCreated()->json('data.id');
        if ($issue) {
            $this->actingAsApi($this->accountant)->postJson("/api/v1/admin/invoices/{$id}/issue")->assertOk();
        }

        return $id;
    }

    private function pay(int $invoice, float $amount): void
    {
        $this->actingAsApi($this->accountant)->postJson("/api/v1/admin/deals/{$invoice}/payments", [
            'amount' => $amount, 'method' => 'cash', 'reference' => 'CASH-'.$invoice, 'evidence' => $this->receipt(),
        ])->assertCreated();
    }

    #[Test]
    public function each_customer_shows_what_they_were_invoiced_and_what_they_still_owe(): void
    {
        $rahim = $this->person('Rahim Traders', '8801711000111');
        $karim = $this->person('Karim Uddin', '8801811000222');
        $first = $this->invoice($rahim, 50000);
        $this->invoice($rahim, 20000);
        $this->pay($first, 30000);
        $paid = $this->invoice($karim, 8000);
        $this->pay($paid, 8000);

        // Neither a draft nor a void invoice is owed, so neither counts.
        $this->invoice($rahim, 99000, issue: false);
        $voided = $this->invoice($karim, 5000);
        $this->actingAsApi($this->accountant)->postJson("/api/v1/admin/deals/{$voided}/void", ['reason' => 'Written in error'])->assertOk();

        $response = $this->actingAsApi($this->accountant)->getJson('/api/v1/admin/customer-accounts')->assertOk();

        $rows = collect($response->json('data'))->keyBy('name');
        $this->assertSame(['Karim Uddin', 'Rahim Traders'], $rows->keys()->all(), 'by name, as the old screen listed them');
        $this->assertEquals([2, 70000, 30000, 40000], [$rows['Rahim Traders']['invoices'], $rows['Rahim Traders']['total'], $rows['Rahim Traders']['paid'], $rows['Rahim Traders']['due']]);
        $this->assertEquals([1, 8000, 8000, 0], [$rows['Karim Uddin']['invoices'], $rows['Karim Uddin']['total'], $rows['Karim Uddin']['paid'], $rows['Karim Uddin']['due']]);
        $this->assertEquals(['all' => 2, 'due' => 1], $response->json('meta.tabs'));
        $this->assertEquals(['invoiced' => 78000, 'due' => 40000], $response->json('meta.totals'));
    }

    #[Test]
    public function the_totals_agree_with_the_invoices_screen(): void
    {
        $rahim = $this->person('Rahim Traders', '8801711000111');
        $karim = $this->person('Karim Uddin', '8801811000222');
        $this->pay($this->invoice($rahim, 45500.50), 10000);
        $this->invoice($karim, 12000);

        $customers = $this->actingAsApi($this->accountant)->getJson('/api/v1/admin/customer-accounts')->assertOk()->json('meta.totals');
        $invoices = $this->actingAsApi($this->accountant)->getJson('/api/v1/admin/invoices')->assertOk()->json('meta.totals');

        $this->assertEquals($invoices, $customers);
    }

    #[Test]
    public function a_website_lead_nobody_has_invoiced_is_not_an_account_but_a_customer_with_nothing_owed_is(): void
    {
        Customer::query()->forceCreate(['name' => 'Enquiry Only', 'phone' => '8801911000333', 'stage' => 'lead', 'source' => 'website_form']);
        $invoicedLead = Customer::query()->forceCreate(['name' => 'Lead With Invoice', 'phone' => '8801911000444', 'stage' => 'lead', 'source' => 'website_form']);
        $this->invoice($invoicedLead, 1000);
        $this->person('Walk-in, never invoiced', '8801611000555');

        $names = collect($this->actingAsApi($this->accountant)->getJson('/api/v1/admin/customer-accounts')->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Lead With Invoice', 'Walk-in, never invoiced'], $names);
    }

    #[Test]
    public function the_balance_due_tab_sorts_by_who_owes_most_and_search_finds_a_phone_number_however_typed(): void
    {
        $small = $this->person('Small Debt', '8801711000111');
        $large = $this->person('Large Debt', '8801811000222');
        $none = $this->person('Nothing Owed', '8801911000333');
        $this->invoice($small, 5000);
        $this->invoice($large, 90000);
        $this->pay($this->invoice($none, 7000), 7000);

        $owing = $this->actingAsApi($this->accountant)->getJson('/api/v1/admin/customer-accounts?balance=due&sort=due')->assertOk();
        $this->assertSame(['Large Debt', 'Small Debt'], array_column($owing->json('data'), 'name'));
        $this->assertEquals(['invoiced' => 95000, 'due' => 95000], $owing->json('meta.totals'));

        $found = $this->actingAsApi($this->accountant)->getJson('/api/v1/admin/customer-accounts?search='.urlencode('01811-000222'))->assertOk()->json('data');
        $this->assertSame(['Large Debt'], array_column($found, 'name'));
    }

    #[Test]
    public function a_customer_is_a_customer_since_their_first_invoice_when_that_came_before_they_were_added(): void
    {
        $carried = $this->person('Carried Across', '8801711000111');
        $id = $this->invoice($carried, 10000, issue: false);
        // An invoice from the old books keeps its own date; the customer record was only made today.
        Invoice::query()->whereKey($id)->update(['issued_on' => '2026-04-15']);
        $this->actingAsApi($this->accountant)->postJson("/api/v1/admin/invoices/{$id}/issue")->assertOk();
        $this->person('Added Today', '8801811000222');

        $rows = collect($this->actingAsApi($this->accountant)->getJson('/api/v1/admin/customer-accounts')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame('2026-04', $rows['Carried Across']['customer_since']);
        $this->assertSame(now('Asia/Dhaka')->format('Y-m'), $rows['Added Today']['customer_since']);
    }

    #[Test]
    public function one_customer_shows_every_invoice_with_the_payments_against_it(): void
    {
        $rahim = $this->person('Rahim Traders', '8801711000111');
        $older = $this->invoice($rahim, 50000);
        $this->pay($older, 20000);
        $this->pay($older, 10000);
        $newer = $this->invoice($rahim, 15000);
        $voided = $this->invoice($rahim, 3000);
        $this->actingAsApi($this->accountant)->postJson("/api/v1/admin/deals/{$voided}/void", ['reason' => 'Written in error'])->assertOk();
        $this->invoice($rahim, 70000, issue: false);

        $account = $this->actingAsApi($this->accountant)->getJson("/api/v1/admin/customer-accounts/{$rahim->id}")->assertOk()->json('data');

        $this->assertSame('Rahim Traders', $account['customer']['name']);
        $this->assertEquals(['invoices' => 2, 'total' => 65000, 'paid' => 30000, 'due' => 35000], $account['totals']);
        // Newest first; the void one is listed so the history reads true, the draft is not an invoice yet.
        $this->assertSame([$voided, $newer, $older], array_column($account['invoices'], 'id'));
        $this->assertSame('void', $account['invoices'][0]['status']);
        $this->assertEquals([20000, 10000], array_column($account['invoices'][2]['payments'], 'amount'));
        $this->assertSame([], $account['invoices'][1]['payments']);
    }

    #[Test]
    public function only_staff_who_see_payments_see_customers_accounts(): void
    {
        $rahim = $this->person('Rahim Traders', '8801711000111');
        $operator = $this->staff('tour_operator');

        $this->actingAsApi($operator)->getJson('/api/v1/admin/customer-accounts')->assertForbidden();
        $this->actingAsApi($operator)->getJson("/api/v1/admin/customer-accounts/{$rahim->id}")->assertForbidden();
        $this->actingAsApi($this->staff('sales_agent'))->getJson('/api/v1/admin/customer-accounts')->assertForbidden();
        $this->actingAsApi($this->accountant)->getJson('/api/v1/admin/customer-accounts/999999')->assertNotFound();
    }
}
