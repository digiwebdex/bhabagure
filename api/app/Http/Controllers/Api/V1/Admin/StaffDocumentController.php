<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\AnswersHrRefusals;
use App\Http\Controllers\Api\V1\Portal\PortalDocumentController;
use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Services\Hr\HrRefused;
use App\Services\Hr\StaffDocuments;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * System → Vault: staff documents (docs/phase-7-hr-attendance-bonus-wallet.md §4.2). The route needs
 * `staff_documents.view`; uploading, replacing and archiving need `staff_documents.manage`. The sidebar badge opens
 * ?status=attention — expired and expiring documents of staff who aren't suspended — and counts the same rows.
 */
class StaffDocumentController extends Controller
{
    use AnswersHrRefusals;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', StaffDocument::ATTENTION, ...StaffDocument::STATUSES])],
            'staff_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(StaffDocument::TYPES)],
            'archived' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['archived'] = $request->boolean('archived');
        $today = StaffDocument::today();

        $page = self::filtered($filters)->with(['staff', 'uploadedBy', 'archivedBy'])
            ->orderByRaw('expires_on IS NULL')->orderBy('expires_on')->orderByDesc('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (StaffDocument $document) => self::row($document, $today))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'status_counts' => collect([StaffDocument::ATTENTION, ...StaffDocument::STATUSES])
                    ->mapWithKeys(fn (string $status) => [$status => self::filtered(['status' => $status, 'archived' => false])->count()]),
            ],
        ]);
    }

    /** @param array<string, mixed> $filters */
    public static function filtered(array $filters): Builder
    {
        $status = $filters['status'] ?? 'all';

        return StaffDocument::query()
            ->when($filters['archived'] ?? false, fn (Builder $query) => $query->whereNotNull('archived_at'), fn (Builder $query) => $query->whereNull('archived_at'))
            ->when($status !== 'all', fn (Builder $query) => $query->withStatus($status))
            ->when($filters['staff_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('staff_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type));
    }

    /** Whose document an upload can be: everyone on the staff, names only, for the Vault's upload form. */
    public function owners(Request $request): JsonResponse
    {
        $this->ensureManage($request);

        return response()->json(['data' => Staff::query()->orderBy('name')->get(['id', 'name', 'employee_code', 'status'])
            ->map(fn (Staff $staff) => ['id' => $staff->id, 'name' => $staff->name, 'employee_code' => $staff->employee_code, 'status' => $staff->status->value])->all()]);
    }

    public function store(Request $request, int $id, StaffDocuments $documents): JsonResponse
    {
        $this->ensureManage($request);
        $owner = Staff::query()->findOrFail($id);
        $data = $request->validate($this->rules());

        try {
            $document = $documents->upload($owner, $data, $request->file('file'), $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => self::row($document->load(['staff', 'uploadedBy', 'archivedBy']))], 201);
    }

    /** A new version of a document; the old one is archived as replaced. */
    public function replace(Request $request, int $id, StaffDocuments $documents): JsonResponse
    {
        $this->ensureManage($request);
        $old = StaffDocument::query()->with('staff')->findOrFail($id);
        $data = $request->validate($this->rules());

        try {
            $document = $documents->upload($old->staff, $data, $request->file('file'), $request->user('staff'), $old);
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => self::row($document->load(['staff', 'uploadedBy', 'archivedBy']))], 201);
    }

    public function archive(Request $request, int $id, StaffDocuments $documents): JsonResponse
    {
        $this->ensureManage($request);
        $document = StaffDocument::query()->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        try {
            $archived = $documents->archive($document, $data['reason'], $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => self::row($archived->load(['staff', 'uploadedBy', 'archivedBy']))]);
    }

    /** The decrypted file, inline and never cached. Every opening is audited. */
    public function file(Request $request, int $id, StaffDocuments $documents): HttpResponse
    {
        $document = StaffDocument::query()->findOrFail($id);

        return PortalDocumentController::inline($documents->open($document, $request->user('staff')), $document->mime, "staff-document-{$document->id}");
    }

    /** @return array<string, mixed> */
    public static function row(StaffDocument $document, ?CarbonImmutable $today = null): array
    {
        $today ??= StaffDocument::today();

        return [
            'id' => $document->id,
            'staff' => ['id' => $document->staff->id, 'name' => $document->staff->name, 'employee_code' => $document->staff->employee_code, 'status' => $document->staff->status->value],
            'type' => $document->type,
            'title' => $document->title,
            'number' => $document->number,
            'issued_on' => $document->issued_on?->toDateString(),
            'expires_on' => $document->expires_on?->toDateString(),
            'status' => $document->status($today),
            // Negative once expired.
            'days_left' => $document->expires_on === null ? null : (int) $today->diffInDays(CarbonImmutable::parse($document->expires_on->toDateString(), 'Asia/Dhaka'), false),
            'mime' => $document->mime,
            'bytes' => $document->bytes,
            'note' => $document->note,
            'uploaded_by' => $document->uploadedBy?->name,
            'uploaded_at' => $document->created_at?->toIso8601String(),
            'archived_at' => $document->archived_at?->toIso8601String(),
            'archived_by' => $document->archivedBy?->name,
            'archive_reason' => $document->archive_reason,
            'replaced_by_id' => $document->replaced_by_id,
        ];
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'type' => ['required', Rule::in(StaffDocument::TYPES)],
            'title' => ['nullable', 'required_if:type,other,certificate', 'string', 'max:120'],
            'number' => ['nullable', 'string', 'max:40'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail) {
                $issued = request()->input('issued_on');
                if (filled($issued) && is_string($value) && $value < $issued) {
                    $fail(__('validation.after_or_equal', ['attribute' => $attribute, 'date' => $issued]));
                }
            }],
            'note' => ['nullable', 'string', 'max:300'],
            'file' => StaffDocuments::fileRules(),
        ];
    }

    private function ensureManage(Request $request): void
    {
        abort_unless($request->user('staff')->can('staff_documents.manage'), Response::HTTP_FORBIDDEN, __('auth.forbidden'));
    }
}
