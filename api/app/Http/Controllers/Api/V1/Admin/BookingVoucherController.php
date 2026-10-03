<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Portal\PortalDocumentController;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingVoucher;
use App\Models\BookingVoucherFile;
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

        $page = self::inView($view, $search)->with(self::WITH)->paginate(30);

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

        $voucher = $vouchers->upload($data, self::booking($data['booking_reference'] ?? null, $staff), $request->file('file'), $staff);

        return response()->json(['data' => self::row($voucher->load(self::WITH))], Response::HTTP_CREATED);
    }

    /**
     * Edits a voucher (docs/booking-vouchers.md §6, 2026-10-03): title, booking (empty unlinks it) and service date, and
     * optionally a new file, which replaces the current one; the current one is kept as an earlier file. Multipart, so
     * the screen sends POST with _method=PUT.
     */
    public function update(Request $request, int $id, BookingVouchers $vouchers): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        $voucher = BookingVoucher::query()->findOrFail($id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'booking_reference' => ['nullable', 'string', 'max:30'],
            'service_date' => ['nullable', 'date_format:Y-m-d'],
            'file' => BookingVouchers::fileRules(required: false),
        ]);

        try {
            $updated = $vouchers->update($voucher, $data, self::booking($data['booking_reference'] ?? null, $staff), $request->file('file'), $staff);
        } catch (DocumentRefused $e) {
            return response()->json(['message' => __("documents.{$e->reason}"), 'code' => $e->reason], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => self::row($updated->load(self::WITH))]);
    }

    /**
     * The booking box's choices: bookings this staff member may see, by number, customer name or phone, newest first.
     * Its own route under the voucher permissions, since a tour operator may manage vouchers without the bookings list.
     */
    public function bookings(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        $search = trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? ''));

        $bookings = Booking::query()->visibleTo($staff)
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('reference', 'like', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
            ->with('customer')->latest('id')->limit(10)->get();

        return response()->json(['data' => $bookings->map(fn (Booking $booking) => [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'customer' => $booking->customer?->name,
            'title' => $booking->package_title_en,
            'travel_start' => $booking->travel_start?->toDateString(),
        ])->all()]);
    }

    /** One of a voucher's earlier files, shown or downloaded like the current one. */
    public function earlierFile(Request $request, int $id, int $fileId, BookingVouchers $vouchers): HttpResponse
    {
        $file = BookingVoucherFile::query()->where('booking_voucher_id', $id)->findOrFail($fileId);

        return self::serve($request, $vouchers->contents($file), $file->mime, $file->original_name, "voucher-{$id}-earlier-{$file->id}");
    }

    private const WITH = ['booking', 'uploadedBy', 'archivedBy', 'earlierFiles.uploadedBy', 'earlierFiles.replacedBy'];

    /** A booking number as staff type it (BH-2609-001), among the bookings they may see; empty is no booking. */
    private static function booking(?string $reference, Staff $staff): ?Booking
    {
        if (blank($reference)) {
            return null;
        }

        return Booking::query()->visibleTo($staff)->where('reference', strtoupper(trim($reference)))->first()
            ?? throw ValidationException::withMessages(['booking_reference' => __('documents.voucher_booking_unknown')]);
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

        return response()->json(['data' => self::row($archived->load(self::WITH))]);
    }

    /** The file, never cached: shown in the browser (PDF viewer or picture), or with ?download=1 saved under its own name. */
    public function file(Request $request, int $id, BookingVouchers $vouchers): HttpResponse
    {
        $voucher = BookingVoucher::query()->findOrFail($id);

        return self::serve($request, $vouchers->contents($voucher), $voucher->mime, $voucher->original_name, "voucher-{$voucher->id}");
    }

    private static function serve(Request $request, string $bytes, string $mime, string $originalName, string $fallbackName): HttpResponse
    {
        if ($request->boolean('download')) {
            // Brackets kept: "Untitled design (1).pdf" downloads under that name; quotes and slashes never reach the header.
            $name = Str::ascii(preg_replace('/[^\w.\-() ]+/u', '_', $originalName) ?: $fallbackName);

            return response($bytes)
                ->header('Content-Type', $mime)
                ->header('Content-Disposition', 'attachment; filename="'.addcslashes($name, '"\\').'"')
                ->header('Cache-Control', 'no-store, private')
                ->header('X-Content-Type-Options', 'nosniff');
        }

        return PortalDocumentController::inline($bytes, $mime, $fallbackName);
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
            // When the current file arrived (a replacement is later than the upload), and the files it replaced.
            'file_uploaded_at' => ($voucher->file_uploaded_at ?? $voucher->created_at)?->toIso8601String(),
            'earlier_files' => $voucher->relationLoaded('earlierFiles') ? $voucher->earlierFiles->map(fn (BookingVoucherFile $file) => [
                'id' => $file->id,
                'mime' => $file->mime,
                'bytes' => $file->bytes,
                'original_name' => $file->original_name,
                'uploaded_by' => $file->uploadedBy?->name,
                'uploaded_at' => $file->uploaded_at?->toIso8601String(),
                'replaced_by' => $file->replacedBy?->name,
                'replaced_at' => $file->created_at?->toIso8601String(),
            ])->all() : [],
            'archived_at' => $voucher->archived_at?->toIso8601String(),
            'archived_by' => $voucher->archivedBy?->name,
            'archive_reason' => $voucher->archive_reason,
        ];
    }
}
