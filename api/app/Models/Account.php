<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    public const CASH = '1000';

    public const BANK = '1010';

    public const MOBILE_WALLETS = '1020';

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

    public const OTHER_EXPENSES = '5290';

    /** Where money sits: the company balance is the journal balance of these (docs/phase-5-admin-core.md §4.6). */
    public const MONEY = [self::CASH, self::BANK, self::MOBILE_WALLETS, self::SSLCOMMERZ_CLEARING];

    protected $fillable = ['code', 'name_en', 'name_bn', 'type'];
}
