<?php

namespace Tests\Feature;

use App\Models\Concerns\AppendOnly;
use App\Support\Database\LedgerQueryGuard;
use App\Support\Database\LedgerTables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/** Structural rules that keep ledgers append-only as the codebase grows (see LedgerTables). */
class LedgerImmutabilityTest extends TestCase
{
    #[Test]
    public function every_model_on_a_ledger_table_is_append_only(): void
    {
        $checked = 0;
        foreach ((new Finder)->files()->in(app_path('Models'))->name('*.php')->notPath('Concerns') as $file) {
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $table = (new $class)->getTable();
            if (LedgerTables::contains($table)) {
                $this->assertContains(AppendOnly::class, class_uses_recursive($class), "{$class} writes to ledger table {$table} and must use AppendOnly");
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(2, $checked, 'Transaction and AuditLog should have been checked');
    }

    #[Test]
    public function no_route_updates_or_deletes_a_ledger(): void
    {
        $offending = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']) !== [])
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => preg_match('#(^|/)(transactions?|ledger|audit|audit-logs|bonus[-_]?transactions?|wallet)(/|$)#', $uri) === 1)
            ->values()
            ->all();

        $this->assertSame([], $offending);
    }

    #[Test]
    public function the_sql_guard_catches_every_mutating_statement_form(): void
    {
        $mutating = [
            'update `transactions` set `amount` = ? where `id` = ?',
            'UPDATE LOW_PRIORITY transactions SET amount = 1',
            'delete from `audit_logs` where `id` = ?',
            'DELETE t FROM transactions t WHERE t.id = 1',
            'truncate table `bonus_transactions`',
            'TRUNCATE wallet_transactions',
            'replace into transactions (id) values (1)',
            'insert into `transactions` (`id`, `amount`) values (?, ?) on duplicate key update `amount` = values(`amount`)',
        ];
        foreach ($mutating as $sql) {
            $this->assertNotNull(LedgerQueryGuard::mutatedLedgerTable($sql), $sql);
        }

        $harmless = [
            'select * from `transactions`',
            'insert into `transactions` (`amount`) values (?)',
            'update `bookings` set `paid_amount` = ?',
            'update `transactions_summary` set x = 1',
            'drop table if exists `transactions`',
            'create table `transactions` (id bigint)',
        ];
        foreach ($harmless as $sql) {
            $this->assertNull(LedgerQueryGuard::mutatedLedgerTable($sql), $sql);
        }
    }
}
