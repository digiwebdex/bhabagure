<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BookingStatus;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\AnswersHrRefusals;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Models\StaffInvitation;
use App\Models\StaffProfile;
use App\Services\Bonus\BonusDesk;
use App\Services\Hr\HrRefused;
use App\Services\Hr\StaffDirectory;
use App\Services\Hr\StaffInvitations;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * HR → Staff & bonus (docs/phase-7-hr-attendance-bonus-wallet.md §4.1): the staff list and a person's record, adding and
 * inviting staff, roles, suspension and password resets — all behind `staff.manage`. Who may do what to whom (own
 * role, super admins, the last super admin) is decided in StaffDirectory.
 */
class StaffController extends Controller
{
    use AnswersHrRefusals;

    /** `current` is everyone who isn't suspended: the list's default. */
    public const STATUSES = ['current', 'active', 'invited', 'suspended', 'all'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'role' => ['nullable', 'string', 'max:100', Rule::exists('roles', 'name')->where('guard_name', 'staff')],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $viewer = $request->user('staff');

        $page = self::filtered($filters)->with(['roles', 'profile', 'invitations' => fn ($query) => $query->open()])->orderBy('name')->orderBy('id')->paginate(30);
        $ids = collect($page->items())->pluck('id')->all();
        $sales = self::closedSalesThisMonth($ids);
        $attention = $viewer->can('staff_documents.view') ? self::documentsNeedingAttention($ids) : null;
        // The design's bonus column (§7), for those who see everyone's commission.
        $bonuses = $viewer->can('bonus.manage') || $viewer->can('commission.view_all') ? BonusDesk::balancesFor($ids) : null;

        return response()->json([
            'data' => collect($page->items())->map(fn (Staff $staff) => self::row($staff, $sales[$staff->id] ?? 0, $attention === null ? null : ($attention[$staff->id] ?? 0))
                + ['bonus' => $bonuses === null ? null : ($bonuses[$staff->id] ?? 0.0)])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'status_counts' => collect(['active', 'invited', 'suspended'])->mapWithKeys(fn (string $status) => [$status => self::filtered(['status' => $status] + $filters)->count()]),
            ],
        ]);
    }

    /** @param array<string, mixed> $filters */
    public static function filtered(array $filters): Builder
    {
        $status = $filters['status'] ?? 'current';

        return Staff::query()
            ->when($status === 'current', fn (Builder $query) => $query->where('status', '!=', StaffStatus::Suspended->value))
            ->when(in_array($status, ['active', 'invited', 'suspended'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->role($role, 'staff'))
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['search'])).'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $term)->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)->orWhere('employee_code', 'like', $term));
            });
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'roles' => array_map(self::roleData(...), StaffDirectory::assignableRoles($request->user('staff'))),
            'payout_methods' => StaffProfile::PAYOUT_METHODS,
            'document_types' => StaffDocument::TYPES,
        ]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->detail(self::find($id), $request->user('staff'))]);
    }

    public function store(Request $request, StaffDirectory $directory, StaffInvitations $invitations): JsonResponse
    {
        $this->normalise($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('staff', 'email')],
            'phone' => ['nullable', 'regex:/^8801[3-9]\d{8}$/'],
            'role' => ['required', 'string', 'max:100'],
            'locale' => ['nullable', Rule::in(['bn', 'en'])],
            'designation' => ['nullable', 'string', 'max:80'],
            'joined_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $by = $request->user('staff');

        try {
            [$staff, $token] = $directory->create($data, $by);
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json([
            'data' => $this->detail($staff, $by),
            'invitation' => $this->invitationData($staff, $token, $invitations),
        ], 201);
    }

    public function update(Request $request, int $id, StaffDirectory $directory): JsonResponse
    {
        $staff = self::find($id);
        $this->normalise($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:190', Rule::unique('staff', 'email')->ignore($staff->id)],
            'phone' => ['sometimes', 'nullable', 'regex:/^8801[3-9]\d{8}$/'],
            'locale' => ['sometimes', 'required', Rule::in(['bn', 'en'])],
            'designation' => ['sometimes', 'nullable', 'string', 'max:80'],
            'joined_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'left_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            // Bangladeshi NIDs: 10 digits (smart card), 13, or 17 with the birth year in front.
            'nid_number' => ['sometimes', 'nullable', 'regex:/^(\d{10}|\d{13}|\d{17})$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'payout_method' => ['sometimes', 'nullable', Rule::in(StaffProfile::PAYOUT_METHODS)],
            'payout_account' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);
        $by = $request->user('staff');

        try {
            $directory->update($staff, $data, $by);
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => $this->detail($staff->refresh(), $by)]);
    }

    public function changeRole(Request $request, int $id, StaffDirectory $directory): JsonResponse
    {
        $staff = self::find($id);
        $data = $request->validate(['role' => ['required', 'string', 'max:100']]);

        try {
            $directory->changeRole($staff, $data['role'], $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => $this->detail($staff->refresh(), $request->user('staff'))]);
    }

    public function suspend(Request $request, int $id, StaffDirectory $directory): JsonResponse
    {
        $staff = self::find($id);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'left_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $directory->suspend($staff, $data['reason'], $data['left_on'] ?? null, $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => $this->detail($staff->refresh(), $request->user('staff'))]);
    }

    public function reactivate(Request $request, int $id, StaffDirectory $directory): JsonResponse
    {
        $staff = self::find($id);

        try {
            $directory->reactivate($staff, $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => $this->detail($staff->refresh(), $request->user('staff'))]);
    }

    /** A fresh invitation for someone who hasn't set a password: emailed, and its link shown once to copy. */
    public function invite(Request $request, int $id, StaffDirectory $directory, StaffInvitations $invitations): JsonResponse
    {
        $staff = self::find($id);

        try {
            $token = $directory->reinvite($staff, $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => $this->detail($staff->refresh(), $request->user('staff')), 'invitation' => $this->invitationData($staff, $token, $invitations)]);
    }

    /** A reset link by email only: whoever asks never sees it. */
    public function passwordReset(Request $request, int $id, StaffDirectory $directory): JsonResponse
    {
        $staff = self::find($id);

        try {
            $email = $directory->sendPasswordReset($staff, $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => ['email' => $email]]);
    }

    /** @return array<string, mixed> */
    public static function row(Staff $staff, int $closedSales, ?int $documentsAttention): array
    {
        /** @var Role|null $role */
        $role = $staff->roles->first();
        $invitation = $staff->relationLoaded('invitations') ? $staff->invitations->firstWhere('purpose', StaffInvitation::INVITE) : null;

        return [
            'id' => $staff->id,
            'employee_code' => $staff->employee_code,
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => $staff->phone,
            'status' => $staff->status->value,
            'role' => $role ? self::roleData($role) : null,
            'is_super_admin' => $role?->name === StaffRole::SuperAdmin->value,
            'designation' => $staff->profile?->designation,
            'joined_on' => $staff->profile?->joined_on?->toDateString(),
            'last_login_at' => $staff->last_login_at?->toIso8601String(),
            'invitation_expires_at' => $invitation?->expires_at?->toIso8601String(),
            // Bookings they own, confirmed this month in Dhaka and not cancelled since.
            'closed_sales_month' => $closedSales,
            // Expired or expiring documents; null for someone who can't see staff documents.
            'documents_attention' => $documentsAttention,
        ];
    }

    /** @return array{name: string, name_en: string, name_bn: string, is_system: bool} */
    public static function roleData(Role $role): array
    {
        return ['name' => $role->name, 'name_en' => (string) $role->name_en, 'name_bn' => (string) $role->name_bn, 'is_system' => (bool) $role->is_system];
    }

    /** @return array<string, mixed> */
    private function detail(Staff $staff, Staff $viewer): array
    {
        $staff->load(['roles', 'profile', 'invitations' => fn ($query) => $query->open()]);
        $profile = $staff->profile;
        $canDocuments = $viewer->can('staff_documents.view');
        $documents = $canDocuments
            ? $staff->documents()->with(['staff', 'uploadedBy', 'archivedBy'])->orderByRaw('archived_at IS NOT NULL')->latest('id')->get()
            : null;
        $mine = $staff->is($viewer);
        // A super admin's record is managed by super admins only.
        $may = ! $staff->isSuperAdmin() || $viewer->isSuperAdmin();
        $today = StaffDocument::today();

        return self::row($staff, self::closedSalesThisMonth([$staff->id])[$staff->id] ?? 0,
            $canDocuments ? (self::documentsNeedingAttention([$staff->id])[$staff->id] ?? 0) : null) + [
                'locale' => $staff->locale,
                'profile' => [
                    'designation' => $profile?->designation,
                    'joined_on' => $profile?->joined_on?->toDateString(),
                    'left_on' => $profile?->left_on?->toDateString(),
                    'date_of_birth' => $profile?->date_of_birth?->toDateString(),
                    'nid_number' => $profile?->nid_number,
                    'address' => $profile?->address,
                    'emergency_contact_name' => $profile?->emergency_contact_name,
                    'emergency_contact_phone' => $profile?->emergency_contact_phone,
                    'payout_method' => $profile?->payout_method,
                    'payout_account' => $profile?->payout_account,
                ],
                'documents' => $documents?->map(fn (StaffDocument $document) => StaffDocumentController::row($document, $today))->values()->all(),
                'actions' => [
                    'edit' => $may,
                    'change_email' => $may && ($staff->status === StaffStatus::Invited || $viewer->isSuperAdmin()),
                    'change_role' => $may && ! $mine,
                    'suspend' => $may && ! $mine && $staff->status !== StaffStatus::Suspended,
                    'reactivate' => $may && $staff->status === StaffStatus::Suspended,
                    'reinvite' => $may && $staff->status === StaffStatus::Invited,
                    'password_reset' => $may && ! $mine && $staff->status === StaffStatus::Active,
                    'upload_documents' => $viewer->can('staff_documents.manage'),
                ],
            ];
    }

    /** @return array{url: string, email: string, expires_at: string} */
    private function invitationData(Staff $staff, string $token, StaffInvitations $invitations): array
    {
        return [
            'url' => StaffInvitations::url($token, StaffInvitation::INVITE),
            'email' => $invitations->email($staff, $token, StaffInvitation::INVITE),
            'expires_at' => now()->addHours(StaffInvitations::INVITE_HOURS)->toIso8601String(),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private static function closedSalesThisMonth(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Booking::query()->whereIn('assigned_staff_id', $ids)
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
            ->where('confirmed_at', '>=', now('Asia/Dhaka')->startOfMonth()->utc())
            ->selectRaw('assigned_staff_id, count(*) as closed')->groupBy('assigned_staff_id')
            ->pluck('closed', 'assigned_staff_id')->map(fn ($count) => (int) $count)->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private static function documentsNeedingAttention(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return StaffDocument::query()->current()->withStatus(StaffDocument::ATTENTION)->whereIn('staff_id', $ids)
            ->selectRaw('staff_id, count(*) as waiting')->groupBy('staff_id')
            ->pluck('waiting', 'staff_id')->map(fn ($count) => (int) $count)->all();
    }

    private static function find(int $id): Staff
    {
        return Staff::query()->findOrFail($id);
    }

    private function normalise(Request $request): void
    {
        if ($request->has('email')) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        }
        if (filled($request->input('phone'))) {
            $request->merge(['phone' => Phone::normalizeBdMobile((string) $request->input('phone')) ?? $request->input('phone')]);
        }
    }
}
