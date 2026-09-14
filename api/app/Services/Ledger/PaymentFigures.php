<?php

namespace App\Services\Ledger;

use App\Enums\PaymentAttemptStatus;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The month's money figures shared by Payments & invoices and the Dashboard (docs/phase-5-admin-core.md §4.2, §4.6), so
 * "Collected" is one number wherever it is shown. Months are Dhaka calendar months; amounts are customer money received
 * (booking and deal payments), net of reversals.
 */
final class PaymentFigures
{
    /** Method cards, grouped as the design shows them. */
    public const METHOD_GROUPS = [
        'bkash' => ['bkash'],
        'nagad' => ['nagad'],
        'sslcommerz' => ['sslcommerz'],
        'cash_bank' => ['cash', 'bank_transfer', 'cheque', 'card_terminal'],
        'rocket' => ['rocket'],
    ];

    /** @return Collection<int, array{key: string, amount: int|float, payments: int}> */
    public static function methodCards(string $month): Collection
    {
        [$from, $to] = self::monthRange($month);
        $rows = Transaction::query()->toBase()->where('category', LedgerService::CATEGORY_PAYMENT)->whereBetween('occurred_at', [$from, $to])
            ->groupBy('method')
            ->selectRaw("method, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS net, SUM(CASE WHEN direction = 'in' AND reverses_transaction_id IS NULL THEN 1 ELSE 0 END) AS payments")
            ->get()->keyBy('method');

        return collect(self::METHOD_GROUPS)->map(fn (array $methods, string $key) => [
            'key' => $key,
            'amount' => Money::toNumber($rows->only($methods)->sum(fn ($row) => (float) $row->net)),
            'payments' => (int) $rows->only($methods)->sum(fn ($row) => (int) $row->payments),
        ])->filter(fn (array $card) => $card['key'] !== 'rocket' || $card['payments'] > 0)->values();
    }

    public static function collected(string $month): int|float
    {
        return Money::toNumber(self::methodCards($month)->sum('amount'));
    }

    /** Invoices issued in the month and not voided. */
    public static function invoiced(string $month): int|float
    {
        $start = Carbon::parse("{$month}-01");

        return Money::toNumber(Invoice::query()->where('status', Invoice::ISSUED)
            ->whereBetween('issued_on', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])->sum('total_amount'));
    }

    /**
     * Collected in the month by the booking's destination; deals and bookings without a package are "other".
     *
     * @return list<array{destination_id: ?int, name_en: ?string, name_bn: ?string, amount: int|float}>
     */
    public static function collectedByDestination(string $month): array
    {
        [$from, $to] = self::monthRange($month);

        return DB::table('transactions')
            ->leftJoin('bookings', 'bookings.id', '=', 'transactions.booking_id')
            ->leftJoin('tour_packages', 'tour_packages.id', '=', 'bookings.tour_package_id')
            ->leftJoin('destinations', 'destinations.id', '=', 'tour_packages.destination_id')
            ->where('transactions.category', LedgerService::CATEGORY_PAYMENT)->whereBetween('transactions.occurred_at', [$from, $to])
            ->groupBy('destinations.id', 'destinations.name_en', 'destinations.name_bn')
            ->selectRaw("destinations.id AS destination_id, destinations.name_en, destinations.name_bn, SUM(CASE WHEN transactions.direction = 'in' THEN transactions.amount ELSE -transactions.amount END) AS amount")
            ->orderByDesc('amount')->get()
            ->map(fn ($row) => ['destination_id' => $row->destination_id === null ? null : (int) $row->destination_id, 'name_en' => $row->name_en, 'name_bn' => $row->name_bn, 'amount' => Money::toNumber($row->amount)])
            ->filter(fn (array $row) => $row['amount'] != 0)->values()->all();
    }

    /** Online payments a person must look at: held for review, or settled with more collected than the customer was shown. */
    public static function reviewQueue(): Builder
    {
        return PaymentAttempt::query()->whereNull('reviewed_at')->where(fn (Builder $q) => $q
            ->where('status', PaymentAttemptStatus::NeedsReview)
            ->orWhere(fn (Builder $settled) => $settled->where('status', PaymentAttemptStatus::Settled)->where('gateway_surcharge', '>', 0)));
    }

    /** @return array{0: Carbon, 1: Carbon} the Dhaka month as UTC instants */
    public static function monthRange(string $month): array
    {
        $start = Carbon::parse("{$month}-01", 'Asia/Dhaka')->startOfMonth();

        return [$start->copy()->utc(), $start->copy()->endOfMonth()->utc()];
    }
}
