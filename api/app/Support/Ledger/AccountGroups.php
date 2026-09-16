<?php

namespace App\Support\Ledger;

use App\Models\Account;

/**
 * The sections the chart of accounts is read in (docs/phase-9-accounts.md §2). A kind — asset, liability, equity,
 * income, expense — is too broad to find anything in: an asset is cash in a drawer, money a customer still owes, and
 * a laptop, and they are looked at for different reasons. Each kind is therefore divided into the sections the client's
 * own books already use, in the order they read them.
 *
 * A section is a label, not a rule: nothing about posting depends on it. An account with no section falls under the
 * "other" section of its kind, so the chart never hides an account.
 */
final class AccountGroups
{
    /**
     * Every section, by kind, in the order the chart lists them: key => [title, what it is for].
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const GROUPS = [
        'asset' => [
            'cash_and_bank' => ['Cash and Bank', 'Money that can be spent today — the office drawer, the bank, the mobile wallets and any float a staff member carries.'],
            'money_in_transit' => ['Money in Transit', 'Money the company has been paid but has not received yet, such as card and wallet payments still to be settled by the gateway.'],
            'receivable' => ['Expected Payments from Customers', 'What customers still owe on invoices that have been issued.'],
            'inventory' => ['Inventory', 'Goods held to sell. A travel agency rarely has any.'],
            'equipment' => ['Property, Plant, Equipment', 'What the company owns and uses for years — computers, furniture, the office fit-out.'],
            'depreciation' => ['Depreciation and Amortization', 'What that equipment has lost in value since it was bought.'],
            'vendor_prepayments' => ['Vendor Prepayments and Vendor Credits', 'Money paid to a supplier before they have provided anything, and credit they still owe the company.'],
            'other_short_term_asset' => ['Other Short-Term Asset', 'Anything else the company expects to turn into money within a year.'],
            'other_long_term_asset' => ['Other Long-Term Asset', 'Anything else the company owns and will hold for more than a year.'],
        ],
        'liability' => [
            'credit_card' => ['Credit Card', 'What is owed on a company card.'],
            'loan' => ['Loan and Line of Credit', 'Money borrowed, and what is still to be repaid.'],
            'payable' => ['Expected Payments to Vendors', 'What the company still owes its suppliers.'],
            'sales_taxes' => ['Sales Taxes', 'VAT and service charge collected from customers and owed to the government.'],
            'payroll_due' => ['Due For Payroll', 'Salary and bonus earned by staff but not yet paid out.'],
            'due_to_owners' => ['Due to You and Other Business Owners', 'Money the company owes its owners.'],
            'customer_prepayments' => ['Customer Prepayments and Customer Credits', 'Money taken before an invoice was issued, and credit a customer can spend on a future trip.'],
            'other_short_term_liability' => ['Other Short-Term Liability', 'Anything else owed within a year.'],
            'other_long_term_liability' => ['Other Long-Term Liability', 'Anything else owed over a longer period.'],
        ],
        'equity' => [
            'owner_contribution' => ['Business Owner Contribution', 'Money or assets the owners have put into the business.'],
            'drawing' => ['Drawing', 'Money the owners have taken out of the business for themselves.'],
            'retained_earnings' => ['Retained Earnings', 'What the business has kept: everything earned less everything spent and drawn, including the balances the books opened with.'],
        ],
        'income' => [
            'income' => ['Income', 'What the company earns from what it sells — tours, tickets, visas and services.'],
            'sale_return' => ['Sale Return', 'Sales given back to the customer, which reduce what was earned.'],
            'discount' => ['Discount', 'Money taken off a price. It reduces income, so it reads as a negative on the profit and loss.'],
            'other_income' => ['Other Income', 'Anything earned outside the usual business, such as a charge collected on an online payment.'],
            'uncategorized_income' => ['Uncategorized Income', 'Money in that nobody has said what it was for yet. Give it an account to keep the books honest.'],
            'fx_gain' => ['Gain On Foreign Exchange', 'What the company gained because a rate moved between agreeing a price in another currency and settling it.'],
        ],
        'expense' => [
            'operating_expense' => ['Operating Expense', 'What it costs to keep the office open — rent, utilities, marketing, travel, stationery.'],
            'cost_of_sales' => ['Cost of Goods Sold', 'What the trips themselves cost: tickets, hotels, visas and the agents who supply them.'],
            'payment_fees' => ['Payment Processing Fee', 'What the bank and the payment gateway charge to take money.'],
            'payroll_expense' => ['Payroll Expense', 'Salary, bonus and everything else paid to staff.'],
            'uncategorized_expense' => ['Uncategorized Expense', 'Money out that nobody has said what it was for yet. Give it an account to keep the books honest.'],
            'fx_loss' => ['Loss On Foreign Exchange', 'What the company lost because a rate moved between agreeing a price in another currency and settling it.'],
        ],
    ];

    /** Where each account the software posts to belongs. Staff accounts carry their own section. */
    public const SYSTEM_GROUPS = [
        Account::CASH => 'cash_and_bank',
        Account::BANK => 'cash_and_bank',
        Account::MOBILE_WALLETS => 'cash_and_bank',
        Account::BKASH => 'cash_and_bank',
        Account::NAGAD => 'cash_and_bank',
        Account::ROCKET => 'cash_and_bank',
        // Taken from the customer, not yet settled by the gateway: money on its way in.
        Account::SSLCOMMERZ_CLEARING => 'money_in_transit',
        Account::RECEIVABLE => 'receivable',
        Account::VAT_PAYABLE => 'sales_taxes',
        Account::OWNER_CAPITAL => 'owner_contribution',
        Account::OWNER_DRAWINGS => 'drawing',
        Account::OPENING_BALANCES => 'retained_earnings',
        Account::PACKAGE_SALES => 'income',
        Account::DEAL_SALES => 'income',
        Account::ONLINE_PAYMENT_CHARGES => 'other_income',
        Account::OTHER_INCOME => 'other_income',
        Account::TOUR_COSTS => 'cost_of_sales',
        Account::GATEWAY_FEES => 'payment_fees',
        Account::OFFICE_RENT => 'operating_expense',
        Account::UTILITIES => 'operating_expense',
        Account::MARKETING => 'operating_expense',
        Account::SALARIES => 'payroll_expense',
        Account::STAFF_BONUSES => 'payroll_expense',
        Account::OTHER_EXPENSES => 'uncategorized_expense',
    ];

    /** The section a new account of this kind falls into when nobody chose one. */
    public const DEFAULTS = [
        'asset' => 'other_short_term_asset',
        'liability' => 'other_short_term_liability',
        'equity' => 'retained_earnings',
        'income' => 'other_income',
        'expense' => 'operating_expense',
    ];

    /** @return list<string> the section keys a kind may use */
    public static function forType(string $type): array
    {
        return array_keys(self::GROUPS[$type] ?? []);
    }

    /** True when this section belongs to that kind — a liability can't sit under Operating Expense. */
    public static function belongsTo(string $group, string $type): bool
    {
        return isset(self::GROUPS[$type][$group]);
    }

    /** @return array<string, mixed> every section of a kind, as the chart screen lists them */
    public static function describe(string $type): array
    {
        return collect(self::GROUPS[$type] ?? [])
            ->map(fn (array $group, string $key) => ['key' => $key, 'title' => $group[0], 'help' => $group[1]])
            ->values()->all();
    }
}
