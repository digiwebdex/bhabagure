<?php

namespace App\Services\Documents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free document numbers from document_sequences, taken under a row lock inside the caller's DB transaction —
 * a rolled-back booking or invoice gives its number back.
 */
final class DocumentNumbers
{
    /** BH-2609-001: per-month counter, at least three digits. */
    public function bookingReference(?Carbon $at = null): string
    {
        $month = ($at ?? now('Asia/Dhaka'))->format('ym');

        return sprintf('BH-%s-%03d', $month, $this->next("booking:{$month}", 'BH'));
    }

    /** INV-0001: one running counter, at least four digits. */
    public function invoiceNumber(): string
    {
        return sprintf('INV-%04d', $this->next('invoice', 'INV'));
    }

    private function next(string $key, string $prefix): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Document numbers are taken inside the transaction that uses them.');
        }

        DB::table('document_sequences')->insertOrIgnore(['key' => $key, 'prefix' => $prefix, 'next_value' => 1, 'updated_at' => now()]);
        $value = (int) DB::table('document_sequences')->where('key', $key)->lockForUpdate()->value('next_value');
        DB::table('document_sequences')->where('key', $key)->update(['next_value' => $value + 1, 'updated_at' => now()]);

        return $value;
    }
}
