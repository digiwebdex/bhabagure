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
use App\Services\Bonus\CommissionDesk;
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
            // The rules, and this month's way to the volume bonus (config bhabaghure.commission, CommissionDesk).
            'rules' => [
                'earns' => CommissionDesk::earns($me),
                'rates' => array_map('floatval', (array) config('bhabaghure.commission.rates')),
                'volume_threshold' => (int) config('bhabaghure.commission.volume.threshold'),
                'volume_rate' => (float) config('bhabaghure.commission.volume.rate'),
            ],
            'volume' => self::volume($accountId, $monthStart, $monthStart->addMonth()),
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

    /**
     * Bookings confirmed in the Dhaka month that are earning this person commission now, and their sale: what the
     * month-end volume bonus will count if nothing changes.
     *
     * @return array{count: int, base: float}
     */
    private static function volume(int $accountId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $entries = BonusTransaction::query()->where('bonus_account_id', $accountId)->where('kind', BonusTransaction::COMMISSION)->whereDoesntHave('reversedBy')
            ->whereHas('booking', fn ($booking) => $booking->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
                ->where('confirmed_at', '>=', $from->utc())->where('confirmed_at', '<', $to->utc()))
            ->get(['id', 'rule']);

        return ['count' => $entries->count(), 'base' => round($entries->sum(fn (BonusTransaction $entry) => (float) ($entry->rule['base'] ?? 0)), 2)];
    }

    /** Commission and volume bonus credited, less what was reversed of them, since a Dhaka month's start (or ever). */
    private static function commission(int $accountId, ?CarbonImmutable $since): float
    {
        $kinds = [BonusTransaction::COMMISSION, BonusTransaction::VOLUME];
        $credited = BonusTransaction::query()->where('bonus_account_id', $accountId)->whereIn('kind', $kinds)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since->utc()))->sum('amount');
        $reversed = BonusTransaction::query()->where('bonus_account_id', $accountId)->where('kind', BonusTransaction::REVERSAL)
            ->whereHas('reverses', fn ($query) => $query->whereIn('kind', $kinds))
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since->utc()))->sum('amount');

        return round((float) $credited - (float) $reversed, 2);
    }
}
