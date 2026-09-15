<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Concerns\UsesWalletDatabase;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §8: the wallet is isolated by MySQL, not by code discipline — and the code
 * is kept apart too. The company user can't read the wallet database, the wallet user can't read the company's, company
 * code never names the wallet, and no company screen, report or export sends a single query to the wallet connection.
 */
class WalletIsolationTest extends TestCase
{
    use UsesWalletDatabase;

    protected $connectionsToTransact = [null, 'wallet'];

    #[Test]
    public function each_database_user_is_refused_the_other_database(): void
    {
        $company = config('database.connections.mysql');
        $wallet = config('database.connections.wallet');
        $this->assertNotSame($company['username'], $wallet['username'], 'The wallet must have its own MySQL user.');

        $this->assertRefused($company, "SELECT COUNT(*) FROM `{$wallet['database']}`.`wallet_transactions`");
        $this->assertRefused($company, "SELECT COUNT(*) FROM `{$wallet['database']}`.`wallet_authenticators`");
        $this->assertRefused($wallet, "SELECT COUNT(*) FROM `{$company['database']}`.`staff`");
        $this->assertRefused($wallet, "SELECT COUNT(*) FROM `{$company['database']}`.`transactions`");
    }

    #[Test]
    public function company_code_never_names_the_wallet_and_wallet_code_uses_only_the_staff_account(): void
    {
        $root = base_path();
        $companyFiles = array_merge($this->php("{$root}/app"), $this->php("{$root}/routes"), $this->php("{$root}/database"), $this->php("{$root}/config"));
        // Where the wallet is wired in, and the wallet itself.
        $allowed = ['bootstrap/app.php', 'bootstrap/providers.php', 'routes/wallet.php', 'config/wallet.php', 'config/database.php'];
        foreach ($companyFiles as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
            if (str_starts_with($relative, 'app/Wallet/') || str_starts_with($relative, 'database/migrations/wallet/') || in_array($relative, $allowed, true)) {
                continue;
            }
            $source = (string) file_get_contents($file);
            $this->assertStringNotContainsString('App\\Wallet', $source, "{$relative} refers to the wallet's code.");
            $this->assertDoesNotMatchRegularExpression("/connection\\(\\s*'wallet'|setConnection\\(\\s*'wallet'|'database'\\s*=>\\s*'wallet'/", $source, "{$relative} opens the wallet connection.");
        }

        // The wallet reads the staff account to check the password, and nothing else of the company's.
        $permitted = ['App\\Models\\Staff', 'App\\Models\\Concerns\\AppendOnly', 'App\\Enums\\StaffStatus', 'App\\Http\\Controllers\\Controller'];
        foreach ($this->php("{$root}/app/Wallet") as $file) {
            preg_match_all('/^use (App\\\\[^;]+);/m', (string) file_get_contents($file), $imports);
            foreach ($imports[1] as $import) {
                if (! str_starts_with($import, 'App\\Wallet\\')) {
                    $this->assertContains($import, $permitted, basename($file)." imports company code: {$import}");
                }
            }
        }
    }

    #[Test]
    public function no_company_screen_report_or_export_sends_a_query_to_the_wallet(): void
    {
        $owner = $this->staff('super_admin');
        // Something in the wallet, so a stray read would have rows to find.
        $session = $this->walletSignIn($owner->email);
        $this->wallet('POST', 'deals', ['name' => 'Private deal', 'total' => 1000, 'advance' => 500], $session)->assertCreated();

        $walletQueries = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$walletQueries) {
            if ($query->connectionName === 'wallet') {
                $walletQueries[] = $query->sql;
            }
        });

        $visited = 0;
        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || str_starts_with($uri, 'api/v1/wallet') || str_contains($uri, '{') || ! str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            $this->actingAsApi($owner)->getJson("/{$uri}");
            $visited++;
        }

        $this->assertGreaterThan(40, $visited, 'The sweep must reach the company screens, reports and exports.');
        $this->assertSame([], $walletQueries, 'A company endpoint queried the wallet database.');
        $this->assertSame(0, DB::connection('mysql')->table('audit_logs')->where('action', 'like', 'wallet%')->count(), 'Wallet events must never reach the company audit log.');
    }

    /** @param array<string, mixed> $connection */
    private function assertRefused(array $connection, string $sql): void
    {
        $pdo = new PDO("mysql:host={$connection['host']};port={$connection['port']}", $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            $pdo->query($sql);
            $this->fail("{$connection['username']} was allowed: {$sql}");
        } catch (PDOException $e) {
            // 1142: SELECT command denied; 1044: access denied to the database.
            $this->assertMatchesRegularExpression('/1142|1044/', $e->getMessage(), $e->getMessage());
        }
    }

    /** @return list<string> */
    private function php(string $directory): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
