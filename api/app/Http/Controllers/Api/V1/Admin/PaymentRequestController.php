<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\Payments\PaymentRequestRefused;
use App\Services\Payments\PaymentRequests;
use App\Support\Payments\PaymentOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales → Payment requests (docs/payment-requests.md): what customers say they paid, with their screenshots. Approving
 * records the payment on the booking (and confirms an inquiry); rejecting tells the customer why. Reading and deciding
 * need transactions.create_manual, the permission "Record payment" needs.
 */
class PaymentRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', PaymentRequest::PENDING, PaymentRequest::APPROVED, PaymentRequest::REJECTED])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $pending = ($filters['status'] ?? PaymentRequest::PENDING) === PaymentRequest::PENDING;
        $page = self::filtered($filters)->with(['booking.customer', 'decidedBy'])
            ->when($pending, fn (Builder $query) => $query->orderBy('id'), fn (Builder $query) => $query->orderByDesc('id'))
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (PaymentRequest $row) => PaymentRequests::forStaff($row))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            // What bKash adds on top, for Approve's "they sent the charge too" (Site settings → Payment); null when none.
            'bkash_charge_percent' => PaymentOptions::settings()['bkash']['chargePercent'] ?? null,
        ]);
    }

    /** The screenshot, private and never cached. */
    public function file(int $id): StreamedResponse
    {
        $row = PaymentRequest::query()->findOrFail($id);
        abort_unless(Storage::disk('local')->exists($row->evidence_path), 404);

        return Storage::disk('local')->response($row->evidence_path, "payment-request-{$row->id}.".pathinfo($row->evidence_path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ]);
    }

    /** The amount staff found in the account (the customer's by default), and for bKash whether they sent its charge too. */
    public function approve(Request $request, int $id, PaymentRequests $requests): JsonResponse
    {
        $row = PaymentRequest::query()->findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'bkash_charge' => ['nullable', 'boolean'],
        ]);

        return $this->decide(fn () => $requests->approve($row, (float) $data['amount'], (bool) ($data['bkash_charge'] ?? false), $request->user('staff')));
    }

    public function reject(Request $request, int $id, PaymentRequests $requests): JsonResponse
    {
        $row = PaymentRequest::query()->findOrFail($id);
        $reason = trim($request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason']);

        return $this->decide(fn () => $requests->reject($row, $reason, $request->user('staff')));
    }

    /** @param array<string, mixed> $filters */
    public static function filtered(array $filters): Builder
    {
        $status = $filters['status'] ?? PaymentRequest::PENDING;
        $search = trim((string) ($filters['search'] ?? ''));
        $digits = (string) preg_replace('/\D/', '', $search);

        return PaymentRequest::query()
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('reference', 'like', "%{$search}%")
                ->orWhereHas('booking', fn (Builder $booking) => $booking->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', "%{$search}%")
                        ->when(strlen($digits) >= 4, fn (Builder $phone) => $phone->orWhere('phone', 'like', "%{$digits}%"))))));
    }

    /** @param callable(): PaymentRequest $action */
    private function decide(callable $action): JsonResponse
    {
        try {
            $row = $action();
        } catch (PaymentRequestRefused $e) {
            return response()->json(['message' => __("payments.requests.{$e->reason}"), 'code' => $e->reason], $e->status);
        }

        return response()->json(['data' => PaymentRequests::forStaff($row->load(['booking.customer', 'decidedBy']))]);
    }
}
