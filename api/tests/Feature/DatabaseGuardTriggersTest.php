<?php

namespace Tests\Feature;

use App\Support\Database\DatabaseGuardTriggers;
use App\Support\Database\LedgerQueryGuard;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceRecords;
use Tests\TestCase;

/**
 * The optional database triggers (DB_GUARD_TRIGGERS), for when the host approves them. They are installed through
 * a second connection — CREATE TRIGGER commits implicitly, which would end the test's rollback transaction —
 * and dropped after that transaction has rolled back. Skipped where MySQL won't let this user create triggers.
 */
class DatabaseGuardTriggersTest extends TestCase
{
    use CreatesFinanceRecords, RefreshDatabase;

    private ConnectionInterface $ddl;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.guard_ddl' => config('database.connections.'.config('database.default'))]);
        $this->ddl = DB::connection('guard_ddl');
        $this->ddl->statement('SET SESSION lock_wait_timeout = 10');

        try {
            DatabaseGuardTriggers::install($this->ddl);
        } catch (QueryException $exception) {
            DatabaseGuardTriggers::drop($this->ddl);
            $this->markTestSkipped('MySQL refused CREATE TRIGGER for this user (needs log_bin_trust_function_creators or SUPER): '.$exception->getMessage());
        }

        // Runs after RefreshDatabase has rolled back, so DROP TRIGGER doesn't wait on the test's table locks.
        $ddl = $this->ddl;
        $this->beforeApplicationDestroyed(function () use ($ddl) {
            DatabaseGuardTriggers::drop($ddl);
            $ddl->disconnect();
        });
    }

    #[Test]
    public function the_database_itself_rejects_ledger_changes_when_triggers_are_installed(): void
    {
        $transaction = $this->transaction(amount: 5000);

        LedgerQueryGuard::withoutGuard(function () use ($transaction) {
            $this->assertRejected(fn () => DB::table('transactions')->where('id', $transaction->id)->update(['amount' => 1]), 'append-only');
            $this->assertRejected(fn () => DB::table('transactions')->where('id', $transaction->id)->delete(), 'append-only');
        });
    }

    #[Test]
    public function the_database_itself_freezes_issued_invoices_when_triggers_are_installed(): void
    {
        $invoice = $this->invoice();
        DB::table('invoice_items')->insert(['invoice_id' => $invoice->id, 'title_en' => 'Package', 'quantity' => 2, 'unit_price' => 75000, 'line_total' => 150000]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'issued']);

        $this->assertRejected(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['total_amount' => 1]), 'frozen snapshot');
        $this->assertRejected(fn () => DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['unit_price' => 1]), 'frozen');
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'void', 'void_reason' => 'Reissued']);
    }

    #[Test]
    public function the_command_reports_installed_triggers(): void
    {
        $this->assertContains('transactions_append_only_update', DatabaseGuardTriggers::installed($this->ddl));

        config(['bhabaghure.database_guard_triggers' => false]);
        Artisan::call('db:guard-triggers', ['action' => 'install']);
        $this->assertStringContainsString('Set DB_GUARD_TRIGGERS=true first', Artisan::output());
    }

    private function assertRejected(callable $write, string $fragment): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertStringContainsStringIgnoringCase($fragment, $exception->getMessage());

            return;
        }

        $this->fail("The database accepted a write the triggers must reject ({$fragment}).");
    }
}
