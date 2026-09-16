<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    public const CASH = '1000';

    public const BANK = '1010';

    /** Shared by bKash, Nagad and Rocket until they got their own accounts; only a legacy balance can remain here. */
    public const MOBILE_WALLETS = '1020';

    public const BKASH = '1021';

    public const NAGAD = '1022';

    public const ROCKET = '1023';

    public const SSLCOMMERZ_CLEARING = '1030';

    public const RECEIVABLE = '1100';

    public const VAT_PAYABLE = '2100';

    public const PACKAGE_SALES = '4000';

    public const ONLINE_PAYMENT_CHARGES = '4100';

    public const GATEWAY_FEES = '5100';

    public const OWNER_CAPITAL = '3000';

    public const OWNER_DRAWINGS = '3100';

    public const OPENING_BALANCES = '3900';

    public const OTHER_INCOME = '4200';

    public const DEAL_SALES = '4300';

    public const TOUR_COSTS = '5000';

    public const OFFICE_RENT = '5200';

    public const SALARIES = '5210';

    public const UTILITIES = '5220';

    public const MARKETING = '5230';

    /** Bonus withdrawals paid out to staff (docs/phase-7-hr-attendance-bonus-wallet.md §7). */
    public const STAFF_BONUSES = '5240';

    public const OTHER_EXPENSES = '5290';

    /**
     * Where money sits: the company balance is the journal balance of these (docs/phase-5-admin-core.md §4.6). Each mobile
     * wallet provider is its own account, so its statement reconciles against the books.
     */
    public const MONEY = [self::CASH, self::BANK, self::BKASH, self::NAGAD, self::ROCKET, self::SSLCOMMERZ_CLEARING];

    /** The kinds an account can be, in the order the chart of accounts lists them. */
    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /**
     * Where a staff-made account's number comes from: the first free number in its kind's range
     * (docs/phase-9-accounts.md §2). Numbers below the range's start belong to the software's own accounts.
     */
    public const RANGES = [
        'asset' => [1500, 1999],
        'liability' => [2500, 2999],
        'equity' => [3500, 3899],
        'income' => [4500, 4999],
        'expense' => [5500, 5999],
    ];

    protected $fillable = ['code', 'name_en', 'name_bn', 'type', 'group', 'is_system', 'is_money', 'description', 'archived_at', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_money' => 'boolean', 'archived_at' => 'datetime'];
    }

    /** Where money sits — the office drawer, the bank, the wallets and any float staff hold: what the company balance counts. */
    public function isMoney(): bool
    {
        return (bool) $this->is_money;
    }

    /** @param Builder<Account> $query */
    public function scopeMoney(Builder $query): void
    {
        $query->where('is_money', true);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
