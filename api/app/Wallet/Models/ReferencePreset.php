<?php

namespace App\Wallet\Models;

/** A saved reference for one direction, filled into the form with a click. */
class ReferencePreset extends WalletModel
{
    public const UPDATED_AT = null;

    protected $table = 'wallet_reference_presets';

    protected $fillable = ['direction', 'label'];
}
