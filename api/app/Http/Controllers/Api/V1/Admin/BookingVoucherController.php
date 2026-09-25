<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Portal\PortalDocumentController;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingVoucher;
use App\Models\Staff;
use App\Services\Documents\BookingVouchers;
use App\Services\Documents\DocumentRefused;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sales → Vouchers: suppliers' confirmation vouchers and contracts for upcoming bookings (docs/booking-vouchers.md).
 * Reading needs vouchers.view or vouchers.manage (sales agent, accountant); uploading and archiving vouchers.manage
 * (admin, tour operator) — decided 2026-09-25.
 */
class BookingVoucherController extends Controller
{
    public const VIEWS = ['upcoming', 'past', 'archived'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(self::VIEWS)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $view = $filters['view'] ?? 'upcoming';
        $search = $filters['search'] ?? null;

        $page = self::inView($view, $search)->with(['booking', 'uploadedBy', 'archivedBy'])->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(self::row(...))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'counts' => collect(self::VIEWS)->mapWithKeys(fn (string $name) => [$name => self::inView($name, $search)->count()]),
            ],
        ]);
    }

    /**
     * Upcoming: today or later, soonest first, then those without a date. Past: before today, latest first. Archived:
     * most recently archived first.
     */
    private static function inView(string $view, ?string $search): Builder
    {
        $today = now('Asia/Dhaka')->toDateString();
        $query = BookingVoucher::query()
            ->when(filled($search), fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('title', 'like', '%'.$search.'%')
                ->orWhere('original_name', 'like', '%'.$search.'%')
                ->orWhereHas('booking', fn (Builder $booking) => $booking->where('reference', 'like', '%'.$search.'%'))));

        return match ($view) {
            'archived' => $query->whereNotNull('archived_at')->orderByDesc('archived_at')->orderByDesc('id'),
            'past' => $query->whereNull('archived_at')->where('service_date', '<', $today)->orderByDesc('service_date')->orderByDesc('id'),
            default => $query->whereNull('archived_at')->where(fn (Builder $q) => $q->whereNull('service_date')->orWhere('service_date', '>=', $today))
                ->orderByRaw('service_date IS NULL')->orderBy('service_date')->orderByDesc('id'),
        };
    }

    public function store(Request $request, BookingVouchers $vouchers): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            // The booking's number as staff see it (BH-2609-001), optional.
            'booking_reference' => ['nullable', 'string', 'max:30'],
            'service_date' => ['nullable', 'date_format:Y-m-d'],
            'file' => BookingVouchers::fileRules(),
        ]);

        $booking = null;
        if (filled($data['booking_reference'] ?? null)) {
            $booking = Booking::query()->visibleTo($staff)->where('reference', strtoupper(trim($data['booking_reference'])))->first()
                ?? throw ValidationException::withMessages(['booking_reference' => __('documents.voucher_booking_unknown')]);
        }

        $voucher = $vouchers->upload($data, $booking, $request->file('file'), $staff);

        return response()->json(['data' => self::row($voucher->load(['booking', 'uploadedBy', 'archivedBy']))], Response::HTTP_CREATED);
    }

    public function archive(Request $request, int $id, BookingVouchers $vouchers): JsonResponse
    {
        $voucher = BookingVoucher::query()->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        try {
            $archived = $vouchers->archive($voucher, $data['reason'], $request->user('staff'));
        } catch (DocumentRefused $e) {
            return response()->json(['message' => __("documents.{$e->reason}"), 'code' => $e->reason], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => self::row($archived->load(['booking', 'uploadedBy', 'archivedBy']))]);
    }

    /** The file, never cached: shown in the browser (PDF viewer or picture), or with ?download=1 saved under its own name. */
    public function file(Request $request, int $id, BookingVouchers $vouchers): HttpResponse
    {
        $voucher = BookingVoucher::query()->findOrFail($id);
        $bytes = $vouchers->contents($voucher);

        if ($request->boolean('download')) {
            $name = Str::ascii(preg_replace('/[^\w.\- ]+/u', '_', $voucher->original_name) ?: "voucher-{$voucher->id}");

            return response($bytes)
                ->header('Content-Type', $voucher->mime)
                ->header('Content-Disposition', 'attachment; filename="'.addcslashes($name, '"\\').'"')
                ->header('Cache-Control', 'no-store, private')
                ->header('X-Content-Type-Options', 'nosniff');
        }

        return PortalDocumentController::inline($bytes, $voucher->mime, "voucher-{$voucher->id}");
    }

    /** @return array<string, mixed> */
    public static function row(BookingVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'title' => $voucher->title,
            'booking' => $voucher->booking ? ['id' => $voucher->booking->id, 'reference' => $voucher->booking->reference] : null,
            'service_date' => $voucher->service_date?->toDateString(),
            'mime' => $voucher->mime,
            'bytes' => $voucher->bytes,
            'original_name' => $voucher->original_name,
            'uploaded_by' => $voucher->uploadedBy?->name,
            'uploaded_at' => $voucher->created_at?->toIso8601String(),
            'archived_at' => $voucher->archived_at?->toIso8601String(),
            'archived_by' => $voucher->archivedBy?->name,
            'archive_reason' => $voucher->archive_reason,
        ];
    }
}
