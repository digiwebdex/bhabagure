<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Staff;
use App\Services\Invoices\InvoicePdf;
use App\Services\Ledger\AccountBooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-9-accounts.md §5: an invoice staff write themselves — as many lines as it needs, each with its own
 * discount and VAT, a draft until it is issued, and then frozen and in the books.
 */
class InvoiceBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Staff
    {
        return $this->staff('admin');
    }

    private function party(array $attributes = []): Customer
    {
        return Customer::query()->forceCreate($attributes + ['name' => 'Corporate Client', 'phone' => '8801711000900', 'stage' => 'customer', 'source' => 'walk_in']);
    }

    #[Test]
    public function a_draft_adds_up_its_lines_and_can_be_changed_until_it_is_issued(): void
    {
        $staff = $this->admin();
        $customer = $this->party();

        // Two lines: 3 × 12,000 less 2,000 = 34,000 with no VAT; 1 × 8,000 + 5% VAT = 400.
        $draft = $this->actingAsApi($staff)->postJson('/api/v1/admin/invoices', [
            'customer_id' => $customer->id,
            'title' => 'Dhaka–Bangkok group',
            'due_on' => now('Asia/Dhaka')->addDays(7)->toDateString(),
            'note' => 'Payment before ticketing',
            'footer' => 'Thank you for travelling with us.',
            'discount_amount' => 1000,
            'discount_label' => 'Repeat customer',
            'lines' => [
                ['title' => 'Air ticket', 'quantity' => 3, 'unit_price' => 12000, 'discount_amount' => 2000],
                ['title' => 'Visa processing', 'quantity' => 1, 'unit_price' => 8000, 'vat_rate' => 5],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame(['draft', null], [$draft['status'], $draft['number']], 'a draft has no number yet');
        $this->assertEquals([42000, 1000, 400, 41400], [$draft['subtotal'], $draft['discount_amount'], $draft['vat_amount'], $draft['total']]);
        $this->assertEquals([34000.0, 8000.0], array_column($draft['lines'], 'line_total'));
        $this->assertNull($draft['share_url'], 'nothing is shared before it is issued');
        $this->assertSame(0, Invoice::query()->where('status', Invoice::ISSUED)->count());

        // Changed while a draft: the lines are rewritten and the totals follow.
        $updated = $this->actingAsApi($staff)->putJson("/api/v1/admin/invoices/{$draft['id']}", [
            'customer_id' => $customer->id,
            'title' => 'Dhaka–Bangkok group',
            'lines' => [['title' => 'Air ticket', 'quantity' => 2, 'unit_price' => 12000]],
        ])->assertOk()->json('data');
        $this->assertEquals([24000, 24000], [$updated['subtotal'], $updated['total']]);
        $this->assertCount(1, $updated['lines']);
    }

    #[Test]
    public function issuing_gives_it_a_number_posts_it_to_the_books_and_freezes_the_figures(): void
    {
        $staff = $this->admin();
        $customer = $this->party();
        $draft = $this->actingAsApi($staff)->postJson('/api/v1/admin/invoices', [
            'customer_id' => $customer->id, 'title' => 'Corporate tour', 'vat_rate' => 0,
            'lines' => [['title' => 'Tour package', 'quantity' => 2, 'unit_price' => 25000, 'vat_rate' => 2]],
        ])->assertCreated()->json('data');

        $issued = $this->actingAsApi($staff)->postJson("/api/v1/admin/invoices/{$draft['id']}/issue")->assertOk()->json('data');
        $this->assertMatchesRegularExpression('/^INV-\d+$/', $issued['number']);
        $this->assertSame(['issued', 'unpaid'], [$issued['status'], $issued['payment_status']]);
        $this->assertEquals(51000, $issued['total'], '50,000 plus 2% VAT');
        $this->assertNotNull($issued['share_url']);

        // The books: receivable owed, sales and VAT credited.
        $chart = collect(app(AccountBooks::class)->chart())->keyBy('code');
        $this->assertEquals(51000, $chart[Account::RECEIVABLE]['balance']);
        $this->assertEquals(50000, $chart[Account::DEAL_SALES]['balance']);
        $this->assertEquals(1000, $chart[Account::VAT_PAYABLE]['balance']);

        // Issued: no more editing, and issuing again changes nothing.
        $this->actingAsApi($staff)->putJson("/api/v1/admin/invoices/{$draft['id']}", [
            'customer_id' => $customer->id, 'title' => 'Changed', 'lines' => [['title' => 'x', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertStatus(409)->assertJsonPath('code', 'invoice_issued');
        $this->actingAsApi($staff)->postJson("/api/v1/admin/invoices/{$draft['id']}/issue")->assertOk()->assertJsonPath('data.number', $issued['number']);

        // Paid through the ledger, as any invoice is; the row then reads paid with nothing due.
        Storage::fake('local');
        $this->actingAsApi($staff)->postJson("/api/v1/admin/deals/{$draft['id']}/payments", [
            'amount' => 51000, 'method' => 'cash', 'reference' => 'CASH-1', 'evidence' => $this->receipt(),
        ])->assertCreated();
        $row = collect($this->actingAsApi($staff)->getJson('/api/v1/admin/invoices?state=paid')->assertOk()->json('data'))->firstWhere('id', $draft['id']);
        $this->assertEquals([51000, 0], [$row['paid'], $row['due']]);
    }

    #[Test]
    public function the_list_counts_each_state_and_finds_an_invoice_by_its_number_or_customer(): void
    {
        $staff = $this->admin();
        $customer = $this->party();
        $other = $this->party(['name' => 'Walk-in Guest', 'phone' => '8801711000911']);

        $make = function (Customer $for, string $title, ?string $dueOn, bool $issue = true) use ($staff) {
            $draft = $this->actingAsApi($staff)->postJson('/api/v1/admin/invoices', [
                'customer_id' => $for->id, 'title' => $title, 'due_on' => $dueOn,
                'lines' => [['title' => $title, 'quantity' => 1, 'unit_price' => 10000]],
            ])->assertCreated()->json('data');
            if ($issue) {
                $this->actingAsApi($staff)->postJson("/api/v1/admin/invoices/{$draft['id']}/issue")->assertOk();
            }

            return $draft['id'];
        };

        $overdue = $make($customer, 'Overdue tour', now('Asia/Dhaka')->subDays(3)->toDateString());
        $make($other, 'Future tour', now('Asia/Dhaka')->addDays(10)->toDateString());
        $make($customer, 'Still a draft', null, issue: false);

        $tabs = $this->actingAsApi($staff)->getJson('/api/v1/admin/invoices')->assertOk()->json('meta.tabs');
        $this->assertSame([3, 1, 2, 0, 0, 1], [$tabs['all'], $tabs['draft'], $tabs['unpaid'], $tabs['partial'], $tabs['paid'], $tabs['overdue']]);

        $late = $this->actingAsApi($staff)->getJson('/api/v1/admin/invoices?state=overdue')->assertOk()->json('data');
        $this->assertSame([$overdue], array_column($late, 'id'));
        $this->assertSame(3, $late[0]['days_overdue']);

        $this->actingAsApi($staff)->getJson('/api/v1/admin/invoices?search=Walk-in')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAsApi($staff)->getJson("/api/v1/admin/invoices?customer_id={$customer->id}")->assertOk()->assertJsonPath('meta.total', 2);

        // A sales agent may read the list; writing needs invoices.manage.
        $agent = $this->staff('sales_agent', ['email' => 'inv.agent@example.test', 'phone' => '8801711000902']);
        $this->actingAsApi($agent)->getJson('/api/v1/admin/invoices')->assertForbidden();
        $accountant = $this->staff('accountant', ['email' => 'inv.acc@example.test', 'phone' => '8801711000903']);
        $this->actingAsApi($accountant)->getJson('/api/v1/admin/invoices')->assertOk();
    }

    #[Test]
    public function the_printed_invoice_says_the_line_discount_the_due_date_and_the_footer(): void
    {
        $staff = $this->admin();
        $customer = $this->party();
        // 2 × 20,000 less 3,000 is 37,000, and 5% VAT on that is 1,850.
        $draft = $this->actingAsApi($staff)->postJson('/api/v1/admin/invoices', [
            'customer_id' => $customer->id,
            'title' => 'Group air tickets',
            'due_on' => '2026-10-15',
            'footer' => 'Payment before ticketing, please.',
            'lines' => [['title' => 'Air ticket', 'quantity' => 2, 'unit_price' => 20000, 'discount_amount' => 3000, 'vat_rate' => 5]],
        ])->assertCreated()->json('data');
        $this->actingAsApi($staff)->postJson("/api/v1/admin/invoices/{$draft['id']}/issue")->assertOk();

        $html = app(InvoicePdf::class)->html(Invoice::query()->findOrFail($draft['id']), header: true, locale: 'en');
        // The amount is the line after its own discount, so the discount and the VAT are said under it.
        $this->assertStringContainsString('Discount', $html);
        $this->assertStringContainsString('3,000', $html);
        $this->assertStringContainsString('VAT 5%', $html);
        $this->assertStringContainsString('Payment before ticketing, please.', $html);
        $this->assertStringContainsString('15 October 2026', $html);
    }

    #[Test]
    public function an_invoice_for_someone_not_on_file_makes_the_customer_record(): void
    {
        $staff = $this->admin();
        $draft = $this->actingAsApi($staff)->postJson('/api/v1/admin/invoices', [
            'customer' => ['name' => 'Walk-in Guest', 'phone' => '8801711000922'],
            'title' => 'Counter sale',
            'lines' => [['title' => 'Air ticket', 'quantity' => 1, 'unit_price' => 30000]],
        ])->assertCreated()->json('data');

        $customer = Customer::query()->where('phone', '8801711000922')->sole();
        $this->assertSame(['Walk-in Guest', 'customer', 'walk_in'], [$customer->name, $customer->stage, $customer->source]);
        $this->assertSame($customer->id, $draft['customer_id']);

        // The same number again bills that record rather than making a second one.
        $this->actingAsApi($staff)->postJson('/api/v1/admin/invoices', [
            'customer' => ['name' => 'Walk-in Guest', 'phone' => '8801711000922'],
            'title' => 'Second counter sale',
            'lines' => [['title' => 'Visa processing', 'quantity' => 1, 'unit_price' => 5000]],
        ])->assertCreated()->assertJsonPath('data.customer_id', $customer->id);
        $this->assertSame(1, Customer::query()->where('phone', '8801711000922')->count());

        // An invoice owed by nobody is refused: the books must always know who owes it.
        $this->actingAsApi($staff)->postJson('/api/v1/admin/invoices', [
            'title' => 'Nobody', 'lines' => [['title' => 'Air ticket', 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    #[Test]
    public function an_invoice_needs_a_line_and_a_total_above_zero(): void
    {
        $staff = $this->admin();
        $customer = $this->party();

        $this->actingAsApi($staff)->postJson('/api/v1/admin/invoices', ['customer_id' => $customer->id, 'title' => 'Empty', 'lines' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->actingAsApi($staff)->postJson('/api/v1/admin/invoices', [
            'customer_id' => $customer->id, 'title' => 'Free of charge', 'lines' => [['title' => 'Gift', 'quantity' => 1, 'unit_price' => 0]],
        ])->assertCreated();
        $free = Invoice::query()->latest('id')->sole();
        $this->actingAsApi($staff)->postJson("/api/v1/admin/invoices/{$free->id}/issue")->assertStatus(409)->assertJsonPath('code', 'invoice_empty');
    }
}
