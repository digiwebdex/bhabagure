<?php

namespace App\Console\Commands;

use App\Models\Staff;
use App\Services\Books\BooksImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Carries the company's books across from the service they were kept in (docs/phase-9-accounts.md §8):
 *
 *   php artisan books:import /path/to/exports            what it would do, changing nothing
 *   php artisan books:import /path/to/exports --write    do it
 *
 * The directory holds the four exports, named as the service produced them:
 * chart-of-accounts.csv · customers.csv · all-invoices.csv · all-transactions.csv
 *
 * Nothing exported is ever committed: the repository is public and these are real customers. The files stay wherever
 * they were put for the length of the run.
 */
class ImportBooks extends Command
{
    protected $signature = 'books:import {directory : Where the four CSV exports are} {--write : Actually write them in; without this nothing changes}';

    protected $description = "Carry the company's books across from the accounting service they were kept in";

    private const FILES = [
        'accounts' => 'chart-of-accounts.csv',
        'customers' => 'customers.csv',
        'invoices' => 'all-invoices.csv',
        'entries' => 'all-transactions.csv',
    ];

    public function handle(BooksImport $import): int
    {
        $directory = rtrim($this->argument('directory'), '/\\');
        $books = [];
        foreach (self::FILES as $key => $file) {
            $path = "{$directory}/{$file}";
            if (! is_file($path)) {
                $this->error("Missing {$file} in {$directory}");

                return self::FAILURE;
            }
            $books[$key] = self::read($path);
            $this->line(sprintf('  %-24s %d rows', $file, count($books[$key])));
        }

        $staff = Staff::query()->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->orderBy('id')->first()
            ?? Staff::query()->orderBy('id')->first();
        if ($staff === null) {
            $this->error('There is nobody to record these entries against: create a staff account first.');

            return self::FAILURE;
        }
        $this->line("  recorded against {$staff->name}");
        $this->newLine();

        if (! $this->option('write')) {
            $this->warn('A dry run: nothing was written. Add --write to carry the books across.');
            $this->summarise($books);

            return self::SUCCESS;
        }

        try {
            $result = $import->run($books, $staff);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Nothing was written. '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Carried across:');
        foreach ($result['counts'] as $what => $count) {
            $this->line(sprintf('  %-12s %d', $what, $count));
        }

        // The point of the whole exercise: the balances here must be the balances there.
        $this->newLine();
        $this->info('Balances, ours against theirs:');
        $wrong = 0;
        foreach ($result['balances'] as $name => $pair) {
            $agrees = abs($pair['ours'] - $pair['theirs']) < 0.01;
            $wrong += $agrees ? 0 : 1;
            $this->line(sprintf('  %-24s %15s %15s  %s', $name, number_format($pair['ours'], 2), number_format($pair['theirs'], 2), $agrees ? 'match' : 'OFF'));
        }

        if ($result['notes'] !== []) {
            $this->newLine();
            $this->info('For somebody to look at:');
            foreach ($result['notes'] as $note) {
                $this->line("  · {$note}");
            }
        }

        $this->newLine();
        $wrong === 0
            ? $this->info('Every balance agrees with the old books.')
            : $this->error("{$wrong} balance(s) do not agree. Read them above before anyone works from these figures.");

        return $wrong === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** What is there now, so a dry run says plainly whether the import can go ahead. */
    private function summarise(array $books): void
    {
        $entries = DB::table('transactions')->count();
        $invoices = DB::table('invoices')->whereNotNull('invoice_number')->count();
        $this->line(sprintf('  The books here hold %d cash entries, %d issued invoices, %d customers and %d bookings.',
            $entries, $invoices, DB::table('customers')->whereNull('deleted_at')->count(), DB::table('bookings')->count()));
        $accounts = count(array_filter($books['accounts'], fn (array $row) => trim($row['account_name']) !== ''));
        $this->line(sprintf('  It would add %d accounts, up to %d customers, %d invoices and %d cash entries.',
            $accounts, count($books['customers']), count($books['invoices']), count($books['entries'])));
        $this->line('  Nothing already here is deleted: a customer with the same phone or email keeps their record and gains the old invoices.');
        $entries + $invoices === 0
            ? $this->info('  The books are empty, so the import can go ahead.')
            : $this->error('  The books are already in use, so the import would refuse to run.');
    }

    /**
     * A CSV with a header row, as the service exports it — quoted fields with commas inside are ordinary here.
     *
     * @return list<array<string, string>>
     */
    private static function read(string $path): array
    {
        $handle = fopen($path, 'r') ?: throw new RuntimeException("Cannot read {$path}");
        $head = fgetcsv($handle, escape: '');
        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            $padded = array_pad($row, count($head), '');
            $line = array_combine($head, array_slice($padded, 0, count($head)));
            if (implode('', $line) !== '') {
                $rows[] = array_map(fn ($v) => (string) $v, $line);
            }
        }
        fclose($handle);

        return $rows;
    }
}
