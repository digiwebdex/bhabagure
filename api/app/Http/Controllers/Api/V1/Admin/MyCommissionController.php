<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\BonusAccount;
use App\Models\BonusTransaction;
use App\Models\BonusWithdrawal;
use App\Models\Booking;
use App\Services\Bonus\BonusDesk;
use App\Services\Bonus\BonusRefused;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * My commission (docs/phase-5-admin-core.md §4.8, docs/phase-7-hr-attendance-bonus-wallet.md §7): the signed-in staff
 * member's own sales, bonus balance and ledger, and their withdrawal requests. The routes take no staff id, so nothing
 * here can show anyone else's; the company balance, other people's commission and profit stay behind their permissions.
 */
class MyCommissionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request)]);
    }

    public function requestWithdrawal(Request $request, BonusDesk $desk): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $desk->request($request->user('staff'), (float) $data['amount'], $data['note'] ?? null);
        } catch (BonusRefused $e) {
            return BonusController::refused($e);
        }

        return response()->json(['data' => $this->payload($request)], Response::HTTP_CREATED);
    }

    public function cancelWithdrawal(Request $request, int $id, BonusDesk $desk): JsonResponse
    {
        // Someone else's request is simply not found here.
        $withdrawal = BonusWithdrawal::query()->where('staff_id', $request->user('staff')->id)->findOrFail($id);

        try {
            $desk->cancel($withdrawal, $request->user('staff'));
        } catch (BonusRefused $e) {
            return BonusController::refused($e);
        }

        return response()->json(['data' => $this->payload($request)]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $me = $request->user('staff');
        $accountId = BonusAccount::query()->where('staff_id', $me->id)->value('id') ?? 0;
        $monthStart = CarbonImmutable::now('Asia/Dhaka')->startOfMonth();

        return [
            'balance' => BonusDesk::balance($accountId),
            'held' => BonusDesk::held($accountId),
            'available' => BonusDesk::available($accountId),
            'min_withdrawal' => BonusDesk::MIN_WITHDRAWAL,
            'sales' => [
                'this_month' => self::sales($me->id, $monthStart, $monthStart->addMonth()),
                'last_month' => self::sales($me->id, $monthStart->subMonth(), $monthStart),
            ],
            'commission' => [
                'this_month' => self::commission($accountId, $monthStart),
                'total' => self::commission($accountId, null),
            ],
            'entries' => BonusTransaction::query()->with(['createdBy', 'booking:id,reference', 'reversedBy:id,reverses_id'])->where('bonus_account_id', $accountId)
                ->latest('id')->limit(50)->get()->map(fn (BonusTransaction $entry) => array_diff_key(BonusController::entryRow($entry), ['reversible' => true]))->all(),
            'withdrawals' => BonusWithdrawal::query()->with(['staff', 'decidedBy', 'paidBy', 'cashTransaction'])->where('staff_id', $me->id)->latest('id')->limit(20)->get()
                ->map(fn (BonusWithdrawal $withdrawal) => ['cancellable' => $withdrawal->status === BonusWithdrawal::PENDING]
                    + array_diff_key(BonusController::withdrawalRow($withdrawal, $me), ['actions' => true]))->all(),
        ];
    }

    /** @return array{count: int, total: float} bookings they own, confirmed in the Dhaka month */
    private static function sales(int $staffId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = Booking::query()->where('assigned_staff_id', $staffId)
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
            ->where('confirmed_at', '>=', $from->utc())->where('confirmed_at', '<', $to->utc())
            ->selectRaw('count(*) as count, coalesce(sum(total_amount), 0) as total')->first();

        return ['count' => (int) $row->count, 'total' => round((float) $row->total, 2)];
    }

    /** Commission credited, less commission reversed, since a Dhaka month's start (or ever). */
    private static function commission(int $accountId, ?CarbonImmutable $since): float
    {
        $credited = BonusTransaction::query()->where('bonus_account_id', $accountId)->where('kind', BonusTransaction::COMMISSION)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since->utc()))->sum('amount');
        $reversed = BonusTransaction::query()->where('bonus_account_id', $accountId)->where('kind', BonusTransaction::REVERSAL)
            ->whereHas('reverses', fn ($query) => $query->where('kind', BonusTransaction::COMMISSION))
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since->utc()))->sum('amount');

        return round((float) $credited - (float) $reversed, 2);
    }
}
