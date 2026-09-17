<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Staff;
use App\Services\Books\BooksImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * docs/phase-9-accounts.md §8: carrying the company's books across from the service they were kept in.
 *
 * The exports here are made up — same shape and same defects as the real ones, none of the real customers. The point
 * of every test is the same: a figure that was true in the old books is still true here, and anything the import could
 * not be sure of is said out loud rather than guessed at.
 */
class BooksImportTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): Staff
    {
        return $this->staff('admin');
    }

    /** Their chart of accounts, in the columns the export uses. */
    private function accounts(array $balances = []): array
    {
        $rows = [
            ['Assets', 'Cash and Bank', 'Cash on Hand', '', $balances['Cash on Hand'] ?? '', 'Sep 15, 2026', 'system', ''],
            ['Assets', 'Cash and Bank', 'Mutual Trust Bank', '', $balances['Mutual Trust Bank'] ?? '', 'Sep 16, 2026', 'custom', ''],
            ['Assets', 'Cash and Bank', 'Field Float', 'BHA003', $balances['Field Float'] ?? '', 'Sep 10, 2026', 'custom', 'KPI Bonus'],
            ['Assets', 'Expected Payments from Customers', 'Accounts Receivable', '', '', 'Never', 'system', ''],
            ['Income', 'Income', 'Sales', '', $balances['Sales'] ?? '', 'Sep 15, 2026', 'system', ''],
            ['Expenses', 'Operating Expenses', 'Office Rent', '', '', 'Never', 'system', ''],
            ['Expenses', 'Operating Expenses', 'Thailand Visa Fee', '', '', 'Never', 'custom', ''],
            ['Liabilities & Credit Cards', 'Sales Taxes', '', '', '', '', '', '(no accounts yet)'],
        ];
        $head = ['account_type', 'sub_category', 'account_name', 'account_id', 'balance', 'last_transaction', 'kind', 'description'];

        return array_map(fn (array $row) => array_combine($head, $row), $rows);
    }

    private function customers(array $rows): array
    {
        $head = ['Company Name', 'First Name', 'Last Name', 'Total Invoice', 'Total Amount', 'Ledger Balance', 'Email', 'Phone', 'Customer Since'];

        return array_map(fn (array $row) => array_combine($head, $row), $rows);
    }

    private function invoices(array $rows): array
    {
        $head = ['invoice_no', 'customer', 'date', 'total_amount', 'due_amount', 'due_note', 'status', 'created_by', 'updated_by'];

        return array_map(fn (array $row) => array_combine($head, $row), $rows);
    }

    private function entries(array $rows): array
    {
        $head = ['transaction_id', 'date', 'type', 'description', 'account', 'category', 'amount', 'direction', 'created_by'];

        return array_map(fn (array $row) => array_combine($head, $row), $rows);
    }

    /** One customer, one invoice half paid, one expense and one transfer — the whole shape in miniature. */
    private function books(array $override = []): array
    {
        return [
            'accounts' => $override['accounts'] ?? $this->accounts(['Cash on Hand' => '50000.00', 'Mutual Trust Bank' => '19700.00', 'Field Float' => '300.00', 'Sales' => '80000.00']),
            'customers' => $override['customers'] ?? $this->customers([
                ['Rahim Traders', '', '', '1', '80,000.00', '30,000.00', 'rahim@example.test', '+880 1711000111', 'Sep 2026'],
            ]),
            'invoices' => $override['invoices'] ?? $this->invoices([
                ['#INV-1040', 'Rahim Traders', 'Sep 03, 2026', '80,000.00', '30,000.00', '', 'Partial', 'Test Agent', ''],
            ]),
            'entries' => $override['entries'] ?? $this->entries([
                ['101', 'Sep 03, 2026', 'Cash In', 'Invoice Payment Collect From Rahim Traders. #INV-1040', 'Cash on Hand', 'Invoice Payment', '50000.00', 'in', 'Test Agent'],
                ['102', 'Sep 04, 2026', 'Cash Out', 'Visa fees', 'Mutual Trust Bank', 'Thailand Visa Fee', '300.00', 'out', 'Test Agent'],
                ['103', 'Sep 10, 2026', 'Transfer', 'Transfer From Mutual Trust Bank To Field Float', 'Field Float', 'Mutual Trust Bank', '300.00', 'in', 'Test Agent'],
                ['104', 'Sep 10, 2026', 'Transfer', 'Transfer From Mutual Trust Bank To Field Float', 'Mutual Trust Bank', 'Field Float', '300.00', 'out', 'Test Agent'],
            ]),
        ];
    }

    private function import(array $override = []): array
    {
        return app(BooksImport::class)->run($this->books($override), $this->owner());
    }

    private function balanceOf(string $code): float
    {
        $id = Account::query()->where('code', $code)->value('id');
        $sums = DB::table('journal_lines')->where('account_id', $id)
            ->selectRaw('COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')->first();

        return round((float) $sums->debit - (float) $sums->credit, 2);
    }

    #[Test]
    public function the_cash_the_old_books_ended_with_is_the_cash_this_one_starts_with(): void
    {
        $result = $this->import();

        // 50,000 collected on the invoice; the bank paid 300 out and moved 300 to Field Float.
        $this->assertSame(50000.00, $this->balanceOf(Account::CASH));
        $this->assertSame(-600.00, $this->balanceOf(Account::BANK));
        $this->assertSame(['ours' => 50000.00, 'theirs' => 50000.00], $result['balances']['Cash on Hand']);
        $this->assertSame(1, $result['counts']['invoices']);
        $this->assertSame(1, $result['counts']['transfers']);
        // The two rows of a transfer are one move, so only the expense and the payment are counted as entries.
        $this->assertSame(2, $result['counts']['entries']);
    }

    #[Test]
    public function an_invoice_comes_across_issued_with_what_was_paid_against_it(): void
    {
        $this->import();

        $invoice = Invoice::query()->where('invoice_number', 'INV-1040')->firstOrFail();
        $this->assertNotNull($invoice->issued_on);
        $this->assertSame('2026-09-03', $invoice->issued_on->toDateString());
        $this->assertSame(Invoice::ISSUED, $invoice->status);
        $this->assertSame(80000.00, (float) $invoice->total_amount);
        $this->assertSame(50000.00, (float) $invoice->paid_amount);
        $this->assertSame('Test Agent', $invoice->sales_agent_name);
        // What is still owed is owed by the customer, not lost: 80,000 invoiced against 50,000 collected.
        $this->assertSame(30000.00, $this->balanceOf(Account::RECEIVABLE));
    }

    #[Test]
    public function the_numbering_carries_on_from_the_last_invoice_of_the_old_books(): void
    {
        $result = $this->import([
            'invoices' => $this->invoices([
                ['#INV-1040', 'Rahim Traders', 'Sep 03, 2026', '80,000.00', '30,000.00', '', 'Partial', 'Test Agent', ''],
                ['#INV-1055', 'Rahim Traders', 'Sep 05, 2026', '12,000.00', '12,000.00', '', 'Unpaid', 'Test Agent', ''],
            ]),
        ]);

        // The gaps in their numbering (deleted invoices) are left as gaps; only the highest number matters.
        $this->assertSame(1056, (int) DB::table('document_sequences')->where('key', 'invoice')->value('next_value'));
        $this->assertContains('The next invoice written here will be INV-1056.', $result['notes']);
    }

    /**
     * The one number that could not be reconciled from the real export, and why the import does not pretend otherwise:
     * their chart of accounts and their own invoice list disagree about Sales, so the invoices win and the chart's
     * figure is written down for somebody to answer.
     */
    #[Test]
    public function sales_is_checked_against_their_invoices_and_their_chart_is_reported_when_it_disagrees(): void
    {
        $result = $this->import([
            'accounts' => $this->accounts(['Cash on Hand' => '50000.00', 'Mutual Trust Bank' => '19700.00', 'Field Float' => '300.00', 'Sales' => '52000.00']),
        ]);

        $this->assertSame(['ours' => 80000.00, 'theirs' => 80000.00], $result['balances']['Sales (their invoices)']);
        $this->assertArrayNotHasKey('Sales', $result['balances']);
        $this->assertNotEmpty(array_filter($result['notes'], fn (string $note) => str_contains($note, 'puts Sales at 52,000.00')
            && str_contains($note, 'both total 80,000.00')));
    }

    #[Test]
    public function when_their_chart_agrees_about_sales_nothing_is_said(): void
    {
        $result = $this->import();

        $this->assertSame(['ours' => 80000.00, 'theirs' => 80000.00], $result['balances']['Sales (their invoices)']);
        $this->assertSame([], array_values(array_filter($result['notes'], fn (string $note) => str_contains($note, 'puts Sales at'))));
    }

    #[Test]
    public function two_cash_accounts_that_mean_one_thing_are_compared_as_one(): void
    {
        $head = ['account_type', 'sub_category', 'account_name', 'account_id', 'balance', 'last_transaction', 'kind', 'description'];
        $accounts = $this->accounts(['Cash on Hand' => '30000.00', 'Mutual Trust Bank' => '19700.00', 'Field Float' => '300.00', 'Sales' => '80000.00']);
        $accounts[] = array_combine($head, ['Assets', 'Cash and Bank', 'Cash', '', '20000.00', 'Sep 13, 2026', 'custom', '']);

        $result = $this->import(['accounts' => $accounts]);

        // 30,000 in one and 20,000 in the other is 50,000 in the single account they became.
        $this->assertSame(['ours' => 50000.00, 'theirs' => 50000.00], $result['balances']['Cash on Hand + Cash']);
    }

    #[Test]
    public function a_phone_number_is_repaired_where_it_can_be_and_reported_where_it_cannot(): void
    {
        $result = $this->import([
            'customers' => $this->customers([
                ['Rahim Traders', '', '', '1', '80,000.00', '30,000.00', '', '+880 01711000111', 'Sep 2026'],
                ['Karim Overseas', '', '', '0', '0.00', '0.00', '', '+880 +880 1712-000333', 'Sep 2026'],
                ['Karim Uddin', '', '', '0', '0.00', '0.00', '', '+971 501234567', 'Sep 2026'],
                ['Nobody At All', '', '', '0', '0.00', '0.00', '', '', 'Sep 2026'],
            ]),
        ]);

        $this->assertSame('8801711000111', Customer::query()->where('name', 'Rahim Traders')->value('phone'));
        $this->assertSame('8801712000333', Customer::query()->where('name', 'Karim Overseas')->value('phone'));
        $this->assertSame('971501234567', Customer::query()->where('name', 'Karim Uddin')->value('phone'));
        $this->assertSame(4, $result['counts']['customers']);

        $notes = implode("\n", $result['notes']);
        $this->assertStringContainsString('Karim Uddin: "+971 501234567" is not a Bangladeshi mobile', $notes);
        $this->assertStringContainsString('Nobody At All: no phone number in the export', $notes);
    }

    #[Test]
    public function one_person_entered_twice_becomes_one_customer(): void
    {
        $result = $this->import([
            'customers' => $this->customers([
                ['Rahim Traders', '', '', '1', '80,000.00', '30,000.00', '', '+880 1711000111', 'Sep 2026'],
                ['Rahim Traders', '', '', '0', '0.00', '0.00', '', '+880 01711 000 111', 'Sep 2026'],
            ]),
        ]);

        $this->assertSame(1, Customer::query()->where('name', 'Rahim Traders')->count());
        $this->assertSame(1, $result['counts']['merged']);
        $this->assertStringContainsString('Rahim Traders: 2 records in the export folded into one', implode("\n", $result['notes']));
    }

    #[Test]
    public function when_merged_records_disagree_about_the_number_both_numbers_are_reported(): void
    {
        $result = $this->import([
            'customers' => $this->customers([
                ['Farid Hossain', '', '', '0', '0.00', '0.00', '', '+880 4915700000011', 'Sep 2026'],
                ['FARID HOSSAIN', '', '', '0', '0.00', '0.00', '', '+880 4915700000022', 'Sep 2026'],
                ['Rahim Traders', '', '', '1', '80,000.00', '30,000.00', '', '+880 1711000111', 'Sep 2026'],
            ]),
            'invoices' => $this->invoices([
                ['#INV-1040', 'Rahim Traders', 'Sep 03, 2026', '80,000.00', '30,000.00', '', 'Partial', 'Test Agent', ''],
                ['#INV-1041', 'FARID HOSSAIN', 'Sep 04, 2026', '12,000.00', '12,000.00', '', 'Unpaid', 'Test Agent', ''],
            ]),
        ]);

        // Neither record is marked as invoiced, so the first leads — and the note shows both numbers so nobody has to guess.
        $farid = Customer::query()->where('name', 'Farid Hossain')->firstOrFail();
        $this->assertSame('4915700000011', $farid->phone);
        $notes = implode("\n", $result['notes']);
        $this->assertStringContainsString('"+880 4915700000011" is not a Bangladeshi mobile — kept as 4915700000011', $notes);
        $this->assertStringContainsString('which carried different numbers (4915700000011, 4915700000022): kept 4915700000011', $notes);
        $this->assertSame(1, Invoice::query()->where('invoice_number', 'INV-1041')->where('customer_id', $farid->id)->count());
    }

    #[Test]
    public function the_record_that_was_invoiced_leads_the_merge_and_keeps_its_number(): void
    {
        $result = $this->import([
            'customers' => $this->customers([
                ['Farid Hossain', '', '', '0', '0.00', '0.00', '', '+880 4915700000011', 'Sep 2026'],
                ['FARID HOSSAIN', '', '', '1', '12,000.00', '12,000.00', '', '+880 4915700000022', 'Sep 2026'],
                ['Rahim Traders', '', '', '1', '80,000.00', '30,000.00', '', '+880 1711000111', 'Sep 2026'],
            ]),
        ]);

        $farid = Customer::query()->where('phone', '4915700000022')->firstOrFail();
        $this->assertSame('FARID HOSSAIN', $farid->name);
        $this->assertStringContainsString('"+880 4915700000022" is not a Bangladeshi mobile — kept as 4915700000022', implode("\n", $result['notes']));
    }

    #[Test]
    public function a_customer_already_here_is_kept_and_their_old_invoices_join_them(): void
    {
        // Came in through the website before the books were carried across.
        $lead = Customer::query()->forceCreate(['name' => 'Rahim (website)', 'phone' => '8801711000111', 'stage' => 'lead', 'source' => 'website_form']);
        $other = Customer::query()->forceCreate(['name' => 'Someone Unrelated', 'phone' => '8801911000222', 'stage' => 'lead', 'source' => 'website_form']);

        $result = $this->import();

        $this->assertSame(0, $result['counts']['customers']);
        $this->assertSame(1, $result['counts']['joined']);
        $this->assertSame(2, Customer::query()->count());
        $this->assertNotNull($other->fresh());
        $this->assertSame('Rahim (website)', $lead->fresh()->name);
        $this->assertSame($lead->id, Invoice::query()->where('invoice_number', 'INV-1040')->value('customer_id'));
        $this->assertStringContainsString('Rahim Traders: already here as Rahim (website) (8801711000111)', implode("\n", $result['notes']));
    }

    #[Test]
    public function an_invoice_naming_somebody_the_export_never_listed_stops_the_whole_import(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('names a customer the export has no record of');

        try {
            $this->import([
                'invoices' => $this->invoices([
                    ['#INV-1040', 'Somebody Else Entirely', 'Sep 03, 2026', '80,000.00', '0.00', '', 'Paid', 'Test Agent', ''],
                ]),
            ]);
        } finally {
            // Nothing of a failed import is left behind: it is one transaction from the first row to the last.
            $this->assertSame(0, DB::table('transactions')->count());
            $this->assertSame(0, Invoice::query()->whereNotNull('invoice_number')->count());
        }
    }

    #[Test]
    public function it_will_not_run_into_books_that_are_already_in_use(): void
    {
        $this->import();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An import only runs into empty books.');

        app(BooksImport::class)->run($this->books(), $this->owner());
    }
}
