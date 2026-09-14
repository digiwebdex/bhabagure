<?php

namespace App\Support\Ledger;

use App\Models\Account;
use App\Services\Ledger\LedgerService;

/**
 * What a cash-book row is for, and where its other side goes in the journal (docs/phase-5-admin-core.md, question 3).
 * A manual cash in or out needs a money account (from its method) and one of these categories; the business line is
 * a tag on the row (the prototype's "Source"), not an account.
 */
final class CashCategories
{
    /** category => [direction it may take: in · out · both, the account on the other side] */
    public const MANUAL = [
        'other_income' => ['in', Account::OTHER_INCOME],
        'owner_capital' => ['in', Account::OWNER_CAPITAL],
        'tour_costs' => ['out', Account::TOUR_COSTS],
        'office_rent' => ['out', Account::OFFICE_RENT],
        'salaries' => ['out', Account::SALARIES],
        'utilities' => ['out', Account::UTILITIES],
        'marketing' => ['out', Account::MARKETING],
        'other_expense' => ['out', Account::OTHER_EXPENSES],
        'owner_drawings' => ['out', Account::OWNER_DRAWINGS],
        // Corrects a money account against Opening balances — the only fix for a wrong opening balance.
        'balance_adjustment' => ['both', Account::OPENING_BALANCES],
    ];

    /** The prototype's "Source" select. */
    public const BUSINESS_LINES = ['tours', 'air_ticketing', 'hotels', 'visa', 'b2b', 'corporate', 'office', 'other'];

    /** Every category a cash-book row can carry, for the filter. */
    public static function all(): array
    {
        return [LedgerService::CATEGORY_PAYMENT, LedgerService::CATEGORY_ONLINE_CHARGE, LedgerService::CATEGORY_GATEWAY_FEE, ...array_keys(self::MANUAL)];
    }

    /** @return list<string> manual categories allowed for a direction */
    public static function forDirection(string $direction): array
    {
        return array_keys(array_filter(self::MANUAL, fn (array $rule) => $rule[0] === $direction || $rule[0] === 'both'));
    }

    public static function isManual(string $category): bool
    {
        return isset(self::MANUAL[$category]);
    }
}
