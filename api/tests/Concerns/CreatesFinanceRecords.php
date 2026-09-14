<?php

namespace Tests\Concerns;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Support\Str;

trait CreatesFinanceRecords
{
    protected function transaction(float $amount = 1000, string $direction = 'in', string $method = 'cash', ?string $externalRef = null, ?int $reverses = null): Transaction
    {
        return Transaction::query()->create([
            'direction' => $direction,
            'amount' => $amount,
            'category' => $reverses ? 'refund' : 'customer_payment',
            'method' => $method,
            'external_ref' => $externalRef,
            'description' => 'Test entry',
            'occurred_at' => now(),
            'reverses_transaction_id' => $reverses,
        ]);
    }

    protected function booking(float $total = 150000): Booking
    {
        $customer = Customer::query()->first() ?? $this->customer();

        // The parts must add up to the total (bookings_total_adds_up): anything above the subtotal is VAT, below is discount.
        return Booking::query()->create([
            'reference' => 'BH-'.Str::upper(Str::random(8)),
            'customer_id' => $customer->id,
            'package_title_en' => 'Mustang Valley Adventure',
            'pax_count' => 2,
            'list_price' => 75000,
            'unit_price' => 75000,
            'subtotal_amount' => 150000,
            'discount_amount' => max(0, 150000 - $total),
            'vat_amount' => max(0, $total - 150000),
            'total_amount' => $total,
            'source' => 'website_form',
        ]);
    }

    protected function invoice(): Invoice
    {
        $customer = $this->customer(['phone' => '8801711000002', 'email' => 'invoice@example.test']);

        return Invoice::query()->create([
            'invoice_number' => 'INV-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'issued_on' => now()->toDateString(),
            'billed_name' => 'Tanvir Hasan',
            'billed_address' => 'Agargaon, Dhaka',
            'package_title_en' => 'Mustang Valley Adventure',
            'pax_count' => 2,
            'unit_price' => 75000,
            'subtotal_amount' => 150000,
            'vat_rate' => 2,
            'vat_amount' => 3000,
            'total_amount' => 153000,
            'status' => 'draft',
            'share_token' => Str::random(40),
        ]);
    }
}
