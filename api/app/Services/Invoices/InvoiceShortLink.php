<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * A short link to an invoice's public share page, for SMS: APP_URL/i/{10 letters and digits} redirects to
 * /api/v1/public/invoices/{share token}. Ten base-62 characters (8×10¹⁷ codes) behind a rate limit can't be guessed;
 * the 40-character share token stays the real key and never appears in an SMS.
 */
final class InvoiceShortLink
{
    public const LENGTH = 10;

    public static function url(Invoice $invoice): string
    {
        return rtrim((string) config('bhabaghure.short_link_base'), '/').'/i/'.self::code($invoice);
    }

    public static function code(Invoice $invoice): string
    {
        for ($try = 0; $invoice->short_code === null; $try++) {
            try {
                // Not part of the frozen billing snapshot, so an issued invoice can take one.
                $invoice->forceFill(['short_code' => Str::random(self::LENGTH)])->save();
            } catch (UniqueConstraintViolationException $e) {
                $invoice->short_code = null;
                if ($try >= 3) {
                    throw $e;
                }
            }
        }

        return $invoice->short_code;
    }
}
