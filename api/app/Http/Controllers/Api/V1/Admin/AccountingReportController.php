<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Ledger\AccountBooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The accounting reports (docs/phase-9-accounts.md §4): one account's transactions with its running balance, and the
 * general ledger across every account. Both read the journal, so they always agree with the chart of accounts, and
 * both download as CSV for the accountant's spreadsheet.
 */
class AccountingReportController extends Controller
{
    public function accountTransactions(Request $request, AccountBooks $books): JsonResponse|StreamedResponse
    {
        $filters = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', 'in:csv'],
        ]);
        $account = Account::query()->findOrFail($filters['account_id']);
        $report = $books->accountTransactions($account, $filters['from'] ?? null, $filters['to'] ?? null);

        if (($filters['format'] ?? null) === 'csv') {
            return self::csv("account-{$account->code}.csv", ['Date', 'Entry', 'Description', 'Debit', 'Credit', 'Balance'], array_map(
                fn (array $row) => [$row['date'], $row['entry_id'], $row['description'], $row['debit'], $row['credit'], $row['balance']],
                $report['rows'],
            ), [['', '', 'Opening balance', '', '', $report['opening']]]);
        }

        return response()->json([
            'data' => $report['rows'],
            'meta' => [
                'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name_en, 'type' => $account->type],
                'opening' => $report['opening'],
                'closing' => $report['closing'],
                'debit' => round(array_sum(array_column($report['rows'], 'debit')), 2),
                'credit' => round(array_sum(array_column($report['rows'], 'credit')), 2),
            ],
        ]);
    }

    public function generalLedger(Request $request, AccountBooks $books): JsonResponse|StreamedResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', 'in:csv'],
        ]);
        $report = $books->trialBalance($filters['from'] ?? null, $filters['to'] ?? null);

        if (($filters['format'] ?? null) === 'csv') {
            return self::csv('general-ledger.csv', ['Code', 'Account', 'Kind', 'Debit', 'Credit', 'Balance'], array_map(
                fn (array $row) => [$row['code'], $row['name'], $row['type'], $row['debit'], $row['credit'], $row['balance']],
                $report['rows'],
            ), [['', 'Total', '', $report['totals']['debit'], $report['totals']['credit'], '']]);
        }

        return response()->json(['data' => $report['rows'], 'meta' => ['totals' => $report['totals'], 'types' => Account::TYPES]]);
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     * @param  list<list<mixed>>  $footer
     */
    private static function csv(string $filename, array $headings, array $rows, array $footer = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows, $footer) {
            $handle = fopen('php://output', 'wb');
            // A byte-order mark, so Excel opens the file as UTF-8 and Bangla names stay readable.
            fwrite($handle, "\xEF\xBB\xBF");
            foreach ([$headings, ...$footer, ...$rows] as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
