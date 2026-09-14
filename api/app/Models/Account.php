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

    protected $fillable = ['code', 'name_en', 'name_bn', 'type'];
}
