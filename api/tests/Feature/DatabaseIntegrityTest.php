<?php

namespace Tests\Feature;

use App\Exceptions\InvoiceFrozen;
use App\Exceptions\LedgerImmutable;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Transaction;
use App\Support\WriteScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceRecords;
use Tests\TestCase;
use Throwable;

/**
 * The designer's schema notes. Ledger and invoice rules are enforced in the application (the optional database
 * triggers are covered by DatabaseGuardTriggersTest); constraints and generated columns by MySQL itself.
 */
class DatabaseIntegrityTest extends TestCase
{
    use CreatesFinanceRecords, RefreshDatabase;

    #[Test]
    public function transactions_cannot_be_changed_through_eloquent_the_query_builder_or_raw_sql(): void
    {
        $transaction = $this->transaction(amount: 5000);
        $id = $transaction->id;

        $attempts = [
            'model update' => fn () => $transaction->update(['description' => 'edited']),
            'model delete' => fn () => $transaction->delete(),
            'mass update' => fn () => Transaction::query()->whereKey($id)->update(['amount' => 1]),
            'mass delete' => fn () => Transaction::query()->whereKey($id)->delete(),
            'increment' => fn () => Transaction::query()->whereKey($id)->increment('amount'),
            'DB::table update' => fn () => DB::table('transactions')->where('id', $id)->update(['amount' => 1]),
            'DB::table delete' => fn () => DB::table('transactions')->where('id', $id)->delete(),
            'DB::table upsert' => fn () => DB::table('transactions')->upsert(['id' => $id, 'amount' => 1], ['id'], ['amount']),
            'raw UPDATE' => fn () => DB::update('update transactions set amount = 1 where id = ?', [$id]),
            'raw DELETE' => fn () => DB::statement('DELETE FROM `transactions` WHERE id = ?', [$id]),
            'TRUNCATE' => fn () => DB::statement('TRUNCATE TABLE transactions'),
            'REPLACE' => fn () => DB::insert("REPLACE INTO transactions (id, direction, amount, category, method, description, occurred_at) VALUES (?, 'in', 1, 'other', 'cash', 'x', NOW())", [$id]),
        ];

        foreach ($attempts as $name => $attempt) {
            $this->assertRefused($attempt, LedgerImmutable::class, $name);
        }

        $this->assertSame('5000.00', DB::table('transactions')->where('id', $id)->value('amount'));
        $this->assertSame(1, Transaction::query()->count());
    }

    #[Test]
    public function audit_logs_are_append_only(): void
    {
        $log = AuditLog::query()->create(['action' => 'test.event']);

        $this->assertRefused(fn () => $log->update(['action' => 'changed']), LedgerImmutable::class, 'model update');
        $this->assertRefused(fn () => AuditLog::query()->delete(), LedgerImmutable::class, 'mass delete');
        $this->assertRefused(fn () => DB::table('audit_logs')->update(['action' => 'changed']), LedgerImmutable::class, 'DB::table update');
    }

    #[Test]
    public function a_correction_is_a_reversing_entry_and_each_entry_can_be_reversed_only_once(): void
    {
        $original = $this->transaction(amount: 5000);
        $this->transaction(amount: 5000, direction: 'out', reverses: $original->id);

        $this->assertSame(0.0, (float) DB::table('transactions')->selectRaw("SUM(CASE direction WHEN 'in' THEN amount ELSE -amount END) AS balance")->value('balance'));
        $this->assertDatabaseRejects(fn () => $this->transaction(amount: 5000, direction: 'out', reverses: $original->id), 'Duplicate');
    }

    #[Test]
    public function transaction_amounts_must_be_positive(): void
    {
        $this->assertDatabaseRejects(fn () => $this->transaction(amount: 0), 'transactions_amount_positive');
        $this->assertDatabaseRejects(fn () => $this->transaction(amount: -10), 'transactions_amount_positive');
    }

    #[Test]
    public function the_same_gateway_payment_cannot_be_recorded_twice(): void
    {
        $this->transaction(method: 'sslcommerz', externalRef: 'SSL-123');

        $this->assertDatabaseRejects(fn () => $this->transaction(method: 'sslcommerz', externalRef: 'SSL-123'), 'Duplicate');
    }

    #[Test]
    public function booking_due_amount_is_generated_from_total_and_paid_and_cannot_be_written(): void
    {
        $booking = $this->booking(total: 153000);
        DB::table('bookings')->where('id', $booking->id)->update(['paid_amount' => 50000]);

        $this->assertSame('103000.00', DB::table('bookings')->where('id', $booking->id)->value('due_amount'));
        $this->assertDatabaseRejects(fn () => DB::table('bookings')->where('id', $booking->id)->update(['due_amount' => 0]), 'generated column');
    }

    #[Test]
    public function money_columns_are_exact_decimals(): void
    {
        $booking = $this->booking(total: 131980.50);
        $type = DB::selectOne("SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'total_amount'");

        $this->assertSame(['decimal', 12, 2], [$type->DATA_TYPE, (int) $type->NUMERIC_PRECISION, (int) $type->NUMERIC_SCALE]);
        $this->assertSame('131980.50', $booking->fresh()->total_amount);
    }

    #[Test]
    public function an_issued_invoice_is_a_frozen_snapshot(): void
    {
        $invoice = $this->invoice();
        $invoice->items()->create(['title_en' => 'Mustang Valley Adventure', 'quantity' => 2, 'unit_price' => 75000, 'line_total' => 150000]);

        // Draft: still editable.
        $invoice->update(['unit_price' => 74000]);
        WriteScope::run(WriteScope::INVOICE_STATUS, fn () => $invoice->update(['status' => 'issued']));

        foreach (['unit_price' => 1, 'total_amount' => 1, 'package_title_en' => 'Renamed next season', 'billed_address' => 'New address', 'vat_rate' => 15] as $column => $value) {
            $this->assertRefused(fn () => $invoice->fresh()->update([$column => $value]), InvoiceFrozen::class, $column);
        }
        $this->assertRefused(fn () => $invoice->fresh()->delete(), InvoiceFrozen::class, 'delete');
        $this->assertRefused(fn () => $invoice->items()->first()->update(['unit_price' => 1]), InvoiceFrozen::class, 'line update');
        $this->assertRefused(fn () => $invoice->items()->first()->delete(), InvoiceFrozen::class, 'line delete');
        $this->assertRefused(fn () => $invoice->items()->create(['title_en' => 'Extra', 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1]), InvoiceFrozen::class, 'line insert');

        // Payment cache and voiding stay possible — inside the scopes of LedgerService and InvoiceIssuer only.
        $this->assertRefused(fn () => $invoice->fresh()->forceFill(['paid_amount' => 50000])->save(), LogicException::class, 'paid outside LedgerService');
        $this->assertRefused(fn () => $invoice->fresh()->forceFill(['status' => 'void'])->save(), LogicException::class, 'status outside InvoiceIssuer');
        WriteScope::run(WriteScope::BOOKING_MONEY, fn () => $invoice->fresh()->forceFill(['paid_amount' => 50000, 'payment_status' => 'partial'])->save());
        WriteScope::run(WriteScope::INVOICE_STATUS, fn () => $invoice->fresh()->forceFill(['status' => 'void', 'voided_at' => now(), 'void_reason' => 'Reissued'])->save());
        $this->assertSame('103000.00', DB::table('invoices')->where('id', $invoice->id)->value('balance_due'));
    }

    #[Test]
    public function a_phone_number_is_unique_among_active_customers_only(): void
    {
        $first = $this->customer(['phone' => '8801711000009', 'email' => 'a@example.test']);
        $this->assertDatabaseRejects(fn () => $this->customer(['phone' => '8801711000009', 'email' => 'b@example.test']), 'Duplicate');

        $first->delete();
        $this->customer(['phone' => '8801711000009', 'email' => 'b@example.test']);
        $this->assertSame(2, Customer::withTrashed()->where('phone', '8801711000009')->count());
    }

    #[Test]
    public function passport_numbers_are_encrypted_at_rest_with_a_lookup_hash(): void
    {
        $booking = $this->booking();
        $traveller = $booking->travellers()->create(['full_name' => 'TANVIR HASAN', 'passport_number' => 'A01234567']);

        $stored = DB::table('booking_travellers')->where('id', $traveller->id)->first();
        $this->assertStringNotContainsString('A01234567', $stored->passport_number);
        $this->assertSame('A01234567', $traveller->fresh()->passport_number);
        $this->assertSame(hash_hmac('sha256', 'A01234567', config('app.key')), $stored->passport_number_hash);
    }

    /** @param class-string<Throwable> $expected */
    private function assertRefused(callable $write, string $expected, string $label): void
    {
        try {
            $write();
        } catch (Throwable $thrown) {
            $this->assertInstanceOf($expected, $thrown, "{$label}: ".$thrown->getMessage());

            return;
        }

        $this->fail("{$label} was allowed but must be refused.");
    }

    private function assertDatabaseRejects(callable $write, string $messageFragment): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertStringContainsStringIgnoringCase($messageFragment, $exception->getMessage());

            return;
        }

        $this->fail("The database accepted a write it must reject ({$messageFragment}).");
    }
}
