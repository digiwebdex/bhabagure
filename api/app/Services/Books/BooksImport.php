<?php

namespace App\Services\Books;

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Staff;
use App\Services\Invoices\InvoiceBuilder;
use App\Services\Ledger\AccountBooks;
use App\Services\Ledger\LedgerService;
use App\Support\Ledger\AccountGroups;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrying the company's books across from the accounting service it kept them in (docs/phase-9-accounts.md §8).
 *
 * It reads four CSV exports — the chart of accounts, the customers, the invoices and every cash entry — and writes them
 * in as ordinary records: real accounts, real customers, real invoices posted to the journal, and real cash book rows.
 * Nothing about an imported figure is special afterwards; it can be read, reported on and reversed like any other.
 *
 * The one thing it will not do is guess. Anything it cannot place — a phone number that is not a number, a customer
 * with no way to reach them — is carried across as it stands and listed in the report for someone to fix by hand.
 *
 * It is idempotent only in the sense that it refuses to run twice: it needs empty books, because a cash book cannot be
 * edited or deleted afterwards and a half-finished import would have to be unpicked by hand.
 */
final class BooksImport
{
    /** Their account names against ours, where the software already posts somewhere for the same purpose. */
    private const MAPPED = [
        'Cash on Hand' => Account::CASH,
        // The client kept two cash accounts meaning the same thing; they are one here, by their own decision.
        'Cash' => Account::CASH,
        'Mutual Trust Bank' => Account::BANK,
        'Accounts Receivable' => Account::RECEIVABLE,
        'Sales' => Account::DEAL_SALES,
        'Uncategorized Income' => Account::OTHER_INCOME,
        'Office Rent' => Account::OFFICE_RENT,
        'Staff salary' => Account::SALARIES,
        'Tours & Travels' => Account::TOUR_COSTS,
        'Uncategorized Expense' => Account::OTHER_EXPENSES,
        'Online Transaction fees' => Account::GATEWAY_FEES,
        'Payment Gateway Charge' => Account::GATEWAY_FEES,
        'Owner Investment' => Account::OWNER_CAPITAL,
        'Drawings' => Account::OWNER_DRAWINGS,
    ];

    /** Their section names against ours. */
    private const SECTIONS = [
        'Cash and Bank' => 'cash_and_bank',
        'Money in Transit' => 'money_in_transit',
        'Expected Payments from Customers' => 'receivable',
        'Inventory' => 'inventory',
        'Property, Plant, Equipment' => 'equipment',
        'Depreciation and Amortization' => 'depreciation',
        'Vendor Prepayments and Vendor Credits' => 'vendor_prepayments',
        'Other Short-Term Asset' => 'other_short_term_asset',
        'Other Long-Term Asset' => 'other_long_term_asset',
        'Credit Card' => 'credit_card',
        'Loan and Line of Credit' => 'loan',
        'Expected Payments to Vendors' => 'payable',
        'Sales Taxes' => 'sales_taxes',
        'Due For Payroll' => 'payroll_due',
        'Due to You and Other Business Owners' => 'due_to_owners',
        'Customer Prepayments and Customer Credits' => 'customer_prepayments',
        'Other Short-Term Liability' => 'other_short_term_liability',
        'Other Long-Term Liability' => 'other_long_term_liability',
        'Income' => 'income',
        'Sale Return' => 'sale_return',
        'Discount' => 'discount',
        'Other Income' => 'other_income',
        'Uncategorized Income' => 'uncategorized_income',
        'Gain On Foreign Exchange' => 'fx_gain',
        'Operating Expense' => 'operating_expense',
        'Cost of Goods Sold' => 'cost_of_sales',
        'Payment Processing Fee' => 'payment_fees',
        'Payroll Expense' => 'payroll_expense',
        'Uncategorized Expense' => 'uncategorized_expense',
        'Loss On Foreign Exchange' => 'fx_loss',
        'Business Owner Contribution' => 'owner_contribution',
        'Drawing' => 'drawing',
        'Retained Earnings' => 'retained_earnings',
    ];

    private const KINDS = [
        'Assets' => 'asset',
        'Liabilities & Credit Cards' => 'liability',
        'Income' => 'income',
        'Expenses' => 'expense',
        'Equity' => 'equity',
    ];

    /** What the accounts in Cash and Bank are: money staff hold or bank, counted in the company balance. */
    private const MONEY_SECTION = 'cash_and_bank';

    /** @var array<string, int> their account name => our account id */
    private array $accounts = [];

    /** @var array<string, Customer> their customer name (lower-cased) => the record here */
    private array $customers = [];

    /** @var list<string> everything a person should look at afterwards */
    private array $notes = [];

    /** @var array<string, int> */
    private array $counts = ['accounts' => 0, 'customers' => 0, 'joined' => 0, 'invoices' => 0, 'entries' => 0, 'transfers' => 0, 'merged' => 0];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly InvoiceBuilder $builder,
    ) {}

    /**
     * @param  array<string, list<array<string, string>>>  $books  the four exports, already read
     * @return array{counts: array<string, int>, notes: list<string>, balances: array<string, array{ours: float, theirs: float}>}
     */
    public function run(array $books, Staff $staff): array
    {
        $this->guardEmptyBooks();

        DB::transaction(function () use ($books, $staff) {
            $this->importAccounts($books['accounts'], $staff);
            $this->importCustomers($books['customers']);
            $this->importInvoices($books['invoices'], $staff);
            $this->importEntries($books['entries'], $staff);
            $this->continueInvoiceNumbers($books['invoices']);
        });

        // Compared first: it has things to say, and the notes are read after it has said them.
        $balances = $this->compare($books['accounts'], $books['invoices']);

        return ['counts' => $this->counts, 'notes' => $this->notes, 'balances' => $balances];
    }

    /**
     * The books must be empty. An import posts to an append-only cash book, so a second run could not be told from the
     * first and could not be undone — it is refused rather than left for someone to unpick.
     */
    private function guardEmptyBooks(): void
    {
        $existing = DB::table('transactions')->count();
        $invoices = Invoice::query()->whereNotNull('invoice_number')->count();
        if ($existing > 0 || $invoices > 0) {
            throw new RuntimeException("The books already hold {$existing} cash entries and {$invoices} issued invoices. An import only runs into empty books.");
        }
    }

    /** @param list<array<string, string>> $rows */
    private function importAccounts(array $rows, Staff $staff): void
    {
        foreach ($rows as $row) {
            $name = trim($row['account_name']);
            if ($name === '') {
                continue;
            }
            $kind = self::KINDS[trim($row['account_type'])] ?? throw new RuntimeException("Unknown account kind: {$row['account_type']}");
            $section = self::SECTIONS[trim($row['sub_category'])] ?? AccountGroups::DEFAULTS[$kind];

            if (isset(self::MAPPED[$name])) {
                $this->accounts[$name] = Account::query()->where('code', self::MAPPED[$name])->value('id');

                continue;
            }

            $account = Account::query()->create([
                'code' => $this->nextCode($kind),
                'name_en' => $name,
                'name_bn' => $name,
                'type' => $kind,
                'group' => $section,
                'description' => self::description($row['description']),
                'is_system' => false,
                // Their "account ID" is a reference of the client's own, like BHA001 — kept in the description.
                'is_money' => $section === self::MONEY_SECTION,
                'created_by_staff_id' => $staff->id,
            ]);
            if (trim($row['account_id']) !== '') {
                $account->forceFill(['description' => trim(($account->description ?? '').' · '.trim($row['account_id']), ' ·')])->save();
            }
            $this->accounts[$name] = $account->id;
            $this->counts['accounts']++;
        }
    }

    /** The first free number in the kind's range, taken once per account. */
    private function nextCode(string $kind): string
    {
        [$first, $last] = Account::RANGES[$kind];
        $taken = Account::query()->whereBetween('code', [(string) $first, (string) $last])->pluck('code')->map(fn ($c) => (int) $c)->all();
        for ($code = $first; $code <= $last; $code++) {
            if (! in_array($code, $taken, true)) {
                return (string) $code;
            }
        }

        throw new RuntimeException("Every {$kind} account number is taken.");
    }

    /**
     * The customers, with the duplicates their books accumulated folded together: the same name apart from an
     * honorific, where only one of them carries the invoices. The phone is taken from whichever record has a usable
     * one, because that is the only way to reach the person.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function importCustomers(array $rows): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[self::nameKey($row['Company Name'])][] = $row;
        }

        $placeholder = 0;
        foreach ($groups as $group) {
            $withInvoices = array_values(array_filter($group, fn (array $r) => (int) $r['Total Invoice'] > 0));
            // Two records that both have invoices are two customers as far as anyone here can tell: they stay apart.
            $merge = count($withInvoices) <= 1 && count($group) > 1;
            $sets = $merge ? [$group] : array_map(fn (array $r) => [$r], $group);

            foreach ($sets as $set) {
                $lead = $withInvoices[0] ?? $set[0];
                // A Bangladeshi mobile wins; otherwise the number on the record that was actually invoiced stands.
                $phone = null;
                $raw = null;
                foreach ([$lead, ...$set] as $row) {
                    $candidate = self::phone($row['Phone']);
                    if ($candidate !== null && ($phone === null || (! self::isBdMobile($phone) && self::isBdMobile($candidate)))) {
                        $phone = $candidate;
                        $raw = $row['Phone'];
                    }
                }
                $name = trim($lead['Company Name']);
                if ($phone === null) {
                    $phone = '00000000'.str_pad((string) ++$placeholder, 5, '0', STR_PAD_LEFT);
                    $this->notes[] = "{$name}: no phone number in the export — given the placeholder {$phone}.";
                } elseif (! self::isBdMobile($phone)) {
                    $this->notes[] = "{$name}: \"{$raw}\" is not a Bangladeshi mobile — kept as {$phone}.";
                }
                if (count($set) > 1) {
                    $this->counts['merged'] += count($set) - 1;
                    $numbers = array_values(array_unique(array_filter(array_map(fn (array $r) => self::phone($r['Phone']), $set))));
                    $this->notes[] = "{$name}: ".count($set).' records in the export folded into one'
                        .(count($numbers) > 1 ? ', which carried different numbers ('.implode(', ', $numbers)."): kept {$phone}." : '.');
                }

                // Somebody who already came in through the website, or was added by hand, is the same person: their
                // old invoices join that record rather than a second one being made — which the database would refuse.
                $email = trim($lead['Email']) ?: null;
                $existing = Customer::query()->where('phone', $phone)->first()
                    ?? ($email !== null ? Customer::query()->where('email', $email)->first() : null);

                if ($existing !== null) {
                    $customer = $existing;
                    $this->counts['joined']++;
                    $this->notes[] = "{$name}: already here as {$existing->name} ({$existing->phone}) — the old invoices join that record.";
                } else {
                    $customer = Customer::query()->create([
                        'name' => $name,
                        'phone' => $phone,
                        'email' => $email,
                        'stage' => 'customer',
                        'source' => 'walk_in',
                    ]);
                    $this->counts['customers']++;
                }
                foreach ($set as $row) {
                    $this->customers[mb_strtolower(trim($row['Company Name']))] = $customer;
                }
            }
        }
    }

    /** @param list<array<string, string>> $rows */
    private function importInvoices(array $rows, Staff $staff): void
    {
        foreach ($rows as $row) {
            $number = ltrim(trim($row['invoice_no']), '#');
            $customer = $this->customers[mb_strtolower(trim($row['customer']))]
                ?? throw new RuntimeException("Invoice {$number} names a customer the export has no record of: {$row['customer']}");
            $total = self::money($row['total_amount']);
            $issuedOn = self::date($row['date']);

            $invoice = $this->builder->createDraft($customer, [
                // The export carries no line detail, so each invoice is one line at its full amount.
                'title' => 'Travel services',
                'lines' => [['title' => 'Travel services', 'quantity' => 1, 'unit_price' => $total]],
            ], $staff);

            $invoice->forceFill([
                'invoice_number' => $number,
                'issued_on' => $issuedOn->toDateString(),
                'sales_agent_name' => trim($row['created_by']) ?: (trim($row['updated_by']) ?: $staff->name),
                'note' => 'Carried across from the books kept before this software.',
            ])->save();

            $this->builder->issue($invoice, $staff);
            $this->counts['invoices']++;
        }
    }

    /**
     * Every cash entry, oldest first, so each balance builds the way it did. Four kinds:
     * an invoice payment, a transfer between the company's own accounts, money owed from before these books, and
     * everything else — ordinary money in or out against the account the old books used.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function importEntries(array $rows, Staff $staff): void
    {
        usort($rows, fn (array $a, array $b) => [self::date($a['date'])->timestamp, (int) $a['transaction_id']] <=> [self::date($b['date'])->timestamp, (int) $b['transaction_id']]);

        foreach ($rows as $row) {
            $on = self::date($row['date']);
            $money = $this->account($row['account']);
            $amount = self::money($row['amount']);
            $description = trim($row['description']);

            if (trim($row['type']) === 'Transfer') {
                // Each move is two rows in the export; the one leaving says everything, so the other is skipped.
                if ($row['direction'] !== 'out') {
                    continue;
                }
                $this->ledger->recordImportedTransfer($money, $this->account($row['category']), $amount, $description ?: 'Carried across', $staff, $on);
                $this->counts['transfers']++;

                continue;
            }

            if (preg_match('/#(INV-\d+)/', $description, $found) === 1 && trim($row['category']) === 'Invoice Payment') {
                $invoice = Invoice::query()->where('invoice_number', $found[1])->firstOrFail();
                $this->ledger->recordDealPayment($invoice, $amount, LedgerService::methodForAccount($money->code), $description, $staff, null, null, $on, $money);
                $this->counts['entries']++;

                continue;
            }

            // Money owed from before these books start: the cash is real, and the other side is where the books opened.
            $other = trim($row['category']) === 'Accounts Receivable'
                ? Account::query()->where('code', Account::OPENING_BALANCES)->firstOrFail()
                : $this->account($row['category']);

            $this->ledger->recordImportedEntry(
                TransactionDirection::from($row['direction']),
                $amount,
                $money,
                $other,
                self::slug($row['category']),
                $description ?: trim($row['category']),
                $staff,
                $on,
            );
            $this->counts['entries']++;
        }
    }

    /** New invoices carry on from where the old books left off, so no number is ever used twice. */
    private function continueInvoiceNumbers(array $rows): void
    {
        $highest = 0;
        foreach ($rows as $row) {
            $highest = max($highest, (int) preg_replace('/\D/', '', $row['invoice_no']));
        }
        DB::table('document_sequences')->updateOrInsert(
            ['key' => 'invoice'],
            ['prefix' => 'INV', 'next_value' => $highest + 1, 'updated_at' => now()],
        );
        $this->notes[] = 'The next invoice written here will be INV-'.($highest + 1).'.';
    }

    /**
     * What the import produced against what the old books said, so the figures can be checked line by line.
     *
     * Their chart of accounts is not a full ledger. Every expense account in it reads "Never", and so does Accounts
     * Receivable — while the same export shows lakhs spent under those very categories and six customers still owing.
     * Only the cash and bank accounts and Sales carry a figure there at all. So sales is checked against the two lists
     * of theirs that do agree with each other, the invoices and the customers, and what their chart says about Sales is
     * written down for somebody to answer rather than quietly taken as the truth.
     *
     * @param  list<array<string, string>>  $rows
     * @param  list<array<string, string>>  $invoices
     * @return array<string, array{ours: float, theirs: float}>
     */
    private function compare(array $rows, array $invoices): array
    {
        // Two of their accounts can become one of ours, so their figures are added together before being compared —
        // otherwise the merged account looks wrong twice over.
        $theirs = [];
        $names = [];
        foreach ($rows as $row) {
            $name = trim($row['account_name']);
            if ($name === '' || trim($row['balance']) === '' || ! isset($this->accounts[$name])) {
                continue;
            }
            $id = $this->accounts[$name];
            $theirs[$id] = ($theirs[$id] ?? 0) + self::money($row['balance']);
            $names[$id] = isset($names[$id]) ? "{$names[$id]} + {$name}" : $name;
        }

        $sales = $this->accounts['Sales'] ?? null;
        if ($sales !== null) {
            $invoiced = round(array_sum(array_map(static fn (array $row) => self::money($row['total_amount']), $invoices)), 2);
            $charted = $theirs[$sales] ?? null;
            $theirs[$sales] = $invoiced;
            $names[$sales] = 'Sales (their invoices)';
            if ($charted !== null && abs($charted - $invoiced) >= 0.01) {
                $this->notes[] = sprintf(
                    'Their chart of accounts puts Sales at %s, but their own invoice list and customer list both total %s — and their expense accounts and Accounts Receivable show nothing at all, although the same export spends against them. The invoices are what was carried across. Ask them which figure their accountant works from before anyone files anything.',
                    number_format($charted, 2),
                    number_format($invoiced, 2),
                );
            }
        }

        $compared = [];
        foreach ($theirs as $id => $balance) {
            $account = Account::query()->findOrFail($id);
            $sums = DB::table('journal_lines')->where('account_id', $id)
                ->selectRaw('COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')->first();
            $compared[$names[$id]] = [
                // The account's own way round, so income reads positive as it does on both screens.
                'ours' => AccountBooks::balance($account->type, (float) $sums->debit, (float) $sums->credit),
                'theirs' => round($balance, 2),
            ];
        }

        return $compared;
    }

    private function account(string $name): Account
    {
        $id = $this->accounts[trim($name)] ?? throw new RuntimeException("The export uses an account it never listed: {$name}");

        return Account::query()->findOrFail($id);
    }

    /** "Sep 16, 2026" on the day it happened in Dhaka, at midday so no timezone can move it. */
    private static function date(string $value): Carbon
    {
        return Carbon::createFromFormat('M d, Y H:i', trim($value).' 12:00', 'Asia/Dhaka')->utc();
    }

    /**
     * What the client wrote against an account, such as "KPI Bonus". The long stock explanations are the accounting
     * service's own words — they name the product they were written for, and this software already explains what each
     * section is for — so they are left behind rather than carried across.
     */
    private static function description(string $value): ?string
    {
        $text = trim($value);

        return $text === '' || mb_strlen($text) > 300 ? null : $text;
    }

    private static function money(string $value): float
    {
        return round((float) preg_replace('/[^0-9.\-]/', '', $value), 2);
    }

    /** The old books' category as a key the cash book can hold — its column is 30 characters, and the screen shows it. */
    private static function slug(string $value): string
    {
        $slug = mb_strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($value)), '_'));

        return $slug === '' ? 'imported' : trim(mb_substr($slug, 0, 30), '_');
    }

    /** A name without the honorific and the capitals, so two spellings of one person fall together. */
    private static function nameKey(string $name): string
    {
        return preg_replace('/\s+/', ' ', trim(mb_strtolower(preg_replace('/^\s*(mr|mrs|ms)\.?\s+/i', '', trim($name)))));
    }

    /**
     * The digits of a phone number, undoing what years of typing did to it: a country code entered twice, a national
     * zero left in front of it, a foreign number with +880 stuck on the front. What cannot be understood is kept as it
     * is and reported, never thrown away.
     */
    private static function phone(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            return null;
        }
        while (str_starts_with($digits, '880880')) {
            $digits = substr($digits, 3);
        }
        if (preg_match('/^8800(1[3-9]\d{8})$/', $digits, $found) === 1) {
            return '880'.$found[1];
        }
        if (preg_match('/^0(1[3-9]\d{8})$/', $digits, $found) === 1) {
            return '880'.$found[1];
        }
        if (preg_match('/^1[3-9]\d{8}$/', $digits) === 1) {
            return '880'.$digits;
        }
        // +880 in front of a number that plainly belongs to another country: the 880 is the mistake.
        if (preg_match('/^880([2-9]\d{8,12})$/', $digits, $found) === 1) {
            return $found[1];
        }

        return $digits;
    }

    private static function isBdMobile(string $phone): bool
    {
        return preg_match('/^8801[3-9]\d{8}$/', $phone) === 1;
    }
}
