<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * bKash, Nagad and Rocket each get their own journal account (decided 2026-09-14): they settle separately, on different
 * schedules and with different fees, and the accountant reconciles each provider's statement against its own account.
 *
 * Money already in the shared "Mobile wallets" account (1020) is moved with reclassifying journal entries — the journal
 * is append-only, so nothing is edited. Each payment's line on 1020 is attributed to its provider through the cash-book
 * row it was posted from. Anything on 1020 that came from no cash-book row (an opening balance) can't be attributed
 * and stays there, visible as a legacy balance, for the accountant to move with a balance adjustment.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        '1021' => ['bkash', 'bKash', 'বিকাশ'],
        '1022' => ['nagad', 'Nagad', 'নগদ'],
        '1023' => ['rocket', 'Rocket', 'রকেট'],
    ];

    public function up(): void
    {
        foreach (self::ACCOUNTS as $code => [, $en, $bn]) {
            DB::table('accounts')->insertOrIgnore(['code' => $code, 'name_en' => $en, 'name_bn' => $bn, 'type' => 'asset', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('accounts')->where('code', '1020')->update(['name_en' => 'Mobile wallets (before the split)', 'name_bn' => 'মোবাইল ওয়ালেট (আলাদা করার আগে)', 'updated_at' => now()]);

        $accounts = DB::table('accounts')->whereIn('code', ['1020', ...array_keys(self::ACCOUNTS)])->pluck('id', 'code');
        $net = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('transactions', fn ($join) => $join->on('transactions.id', '=', 'journal_entries.source_id')->where('journal_entries.source_type', '=', 'transaction'))
            ->where('journal_lines.account_id', $accounts['1020'])
            ->groupBy('transactions.method')
            ->selectRaw('transactions.method AS method, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS balance')
            ->pluck('balance', 'method');

        $today = now('Asia/Dhaka')->toDateString();
        foreach (self::ACCOUNTS as $code => [$method, $en]) {
            $paisa = (int) round(((float) ($net[$method] ?? 0)) * 100);
            if ($paisa === 0) {
                continue;
            }
            $amount = number_format(abs($paisa) / 100, 2, '.', '');
            $entry = DB::table('journal_entries')->insertGetId([
                'entry_date' => $today, 'description' => "Reclassify {$en} from Mobile wallets to its own account", 'created_at' => now(),
            ]);
            // Dr the provider's account · Cr Mobile wallets for a positive balance; the other way round otherwise.
            DB::table('journal_lines')->insert([
                ['journal_entry_id' => $entry, 'account_id' => $accounts[$code], 'debit' => $paisa > 0 ? $amount : 0, 'credit' => $paisa > 0 ? 0 : $amount, 'created_at' => now()],
                ['journal_entry_id' => $entry, 'account_id' => $accounts['1020'], 'debit' => $paisa > 0 ? 0 : $amount, 'credit' => $paisa > 0 ? $amount : 0, 'created_at' => now()],
            ]);
        }

        $left = (float) DB::table('journal_lines')->where('account_id', $accounts['1020'])->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS balance')->value('balance');
        if ($left != 0.0) {
            Log::warning('Mobile wallets (1020) keeps a balance no cash-book row explains; move it with a balance adjustment.', ['balance' => $left]);
        }
    }

    public function down(): void
    {
        // Append-only: the reclassifying entries stay. Reversing the split would need entries the other way, by hand.
    }
};
