<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Portal\PortalDocumentController;
use App\Http\Controllers\Controller;
use App\Jobs\SendPayslips;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Services\Ledger\EvidenceStore;
use App\Services\Ledger\LedgerService;
use App\Services\Payroll\PayrollDesk;
use App\Services\Payroll\PayrollRefused;
use App\Services\Payroll\PayslipPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR → Salary (docs/phase-7-hr-attendance-bonus-wallet.md §6): a month's sheet from attendance, adjustments, finalising,
 * paying (a company cash-out with its receipt) and payslips; and each person's base salary history. Reading needs
 * payroll.view or payroll.manage; changing needs payroll.manage; reopening a finalised month is the super admin's.
 */
class PayrollController extends Controller
{
    public function sheet(Request $request, PayrollDesk $desk): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now('Asia/Dhaka')->subMonthNoOverflow()->format('Y-m');

        return response()->json(['data' => $this->payload($month, $desk, $request->user('staff'))]);
    }

    public function addAdjustment(Request $request, string $month, PayrollDesk $desk): JsonResponse
    {
        $this->ensureManage($request);
        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('staff', 'id')],
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-9999999,9999999'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        return $this->attempt($month, $desk, $request, fn () => $desk->addAdjustment($month, Staff::query()->findOrFail($data['staff_id']), (float) $data['amount'], $data['reason'], $request->user('staff')));
    }

    public function removeAdjustment(Request $request, int $id, PayrollDesk $desk): JsonResponse
    {
        $this->ensureManage($request);
        $adjustment = PayrollAdjustment::query()->with('run')->findOrFail($id);

        return $this->attempt($adjustment->run->month->format('Y-m'), $desk, $request, fn () => $desk->removeAdjustment($adjustment, $request->user('staff')));
    }

    public function finalise(Request $request, string $month, PayrollDesk $desk): JsonResponse
    {
        $this->ensureManage($request);

        return $this->attempt($month, $desk, $request, function () use ($desk, $month, $request) {
            $run = $desk->finalise($month, $request->user('staff'));
            SendPayslips::dispatch($run->id)->afterCommit();
        });
    }

    public function reopen(Request $request, string $month, PayrollDesk $desk): JsonResponse
    {
        $this->ensureManage($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $run = PayrollRun::query()->where('month', "{$month}-01")->firstOrFail();

        return $this->attempt($month, $desk, $request, fn () => $desk->reopen($run, $data['reason'], $request->user('staff')));
    }

    public function pay(Request $request, int $id, PayrollDesk $desk, EvidenceStore $evidence): JsonResponse
    {
        $this->ensureManage($request);
        $item = PayrollItem::query()->with('run')->findOrFail($id);
        $data = $request->validate([
            'method' => ['required', Rule::in(LedgerService::STAFF_METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'evidence' => EvidenceStore::rules(),
        ]);

        return $this->attempt($item->run->month->format('Y-m'), $desk, $request, fn () => $evidence->with($request->file('evidence'), fn (?string $path) => $desk->markPaid(
            $item, ['method' => $data['method'], 'reference' => $data['reference'] ?? null, 'occurred_on' => $data['occurred_on'] ?? null], $path, $request->user('staff'),
        )));
    }

    public function payslip(Request $request, int $id, PayslipPdf $payslips): HttpResponse
    {
        $item = PayrollItem::query()->with(['staff', 'run'])->findOrFail($id);

        return $this->pdfResponse($item, $payslips, $request);
    }

    /** A person's base salary history, newest first. */
    public function salaries(int $id): JsonResponse
    {
        $person = Staff::query()->findOrFail($id);

        return response()->json(['data' => StaffSalary::query()->with('createdBy')->where('staff_id', $person->id)->orderByDesc('effective_month')->orderByDesc('id')->get()
            ->map(fn (StaffSalary $salary) => [
                'id' => $salary->id,
                'effective_month' => $salary->effective_month->format('Y-m'),
                'amount' => (float) $salary->amount,
                'reason' => $salary->reason,
                'by' => $salary->createdBy?->name,
                'created_at' => $salary->created_at?->toIso8601String(),
            ])->all()]);
    }

    public function setSalary(Request $request, int $id, PayrollDesk $desk): JsonResponse
    {
        $this->ensureManage($request);
        $person = Staff::query()->findOrFail($id);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        try {
            $desk->setSalary($person, $data['month'], (float) $data['amount'], $data['reason'], $request->user('staff'));
        } catch (PayrollRefused $e) {
            return self::refused($e);
        }

        return $this->salaries($person->id)->setStatusCode(Response::HTTP_CREATED);
    }

    /** The signed-in person's finalised months (profile/payslips). */
    public function myPayslips(Request $request): JsonResponse
    {
        return response()->json(['data' => PayrollItem::query()->with('run')->where('staff_id', $request->user('staff')->id)
            ->whereHas('run', fn ($run) => $run->where('status', PayrollRun::FINALISED))->get()
            ->sortByDesc(fn (PayrollItem $item) => $item->run->month)->values()
            ->map(fn (PayrollItem $item) => ['id' => $item->id, 'month' => $item->run->month->format('Y-m'), 'payable' => (float) $item->payable, 'paid_at' => $item->paid_at?->toIso8601String()])
            ->all()]);
    }

    public function myPayslip(Request $request, int $id, PayslipPdf $payslips): HttpResponse
    {
        $item = PayrollItem::query()->with(['staff', 'run'])->where('staff_id', $request->user('staff')->id)->findOrFail($id);
        abort_unless($item->run->status === PayrollRun::FINALISED, Response::HTTP_NOT_FOUND);

        return $this->pdfResponse($item, $payslips, $request);
    }

    /** @return array<string, mixed> */
    private function payload(string $month, PayrollDesk $desk, Staff $viewer): array
    {
        $sheet = $desk->sheet($month);
        $rows = collect($sheet['rows']);
        $finalised = $sheet['run']?->status === PayrollRun::FINALISED;
        $manage = $viewer->can('payroll.manage');
        $monthOver = $month < now('Asia/Dhaka')->format('Y-m');

        return [
            'month' => $month,
            'status' => $finalised ? PayrollRun::FINALISED : PayrollRun::DRAFT,
            'month_over' => $monthOver,
            'finalised_at' => $sheet['run']?->finalised_at?->toIso8601String(),
            'finalised_by' => $finalised ? $sheet['run']->loadMissing('finalisedBy')->finalisedBy?->name : null,
            'methods' => LedgerService::STAFF_METHODS,
            'rules' => $sheet['rules'],
            'rows' => $sheet['rows'],
            'totals' => [
                'base' => $rows->sum(fn (array $row) => $row['base'] ?? 0),
                'deductions' => round($rows->sum(fn (array $row) => $row['figures']['deductions'] ?? 0), 2),
                'adjustments' => round($rows->sum(fn (array $row) => $row['figures']['adjustments'] ?? 0), 2),
                'payable' => $rows->sum(fn (array $row) => $row['figures']['payable'] ?? 0),
                'paid' => $rows->filter(fn (array $row) => $row['paid_at'] !== null)->count(),
                'missing_salary' => $rows->filter(fn (array $row) => $row['base'] === null)->count(),
            ],
            'actions' => [
                'adjust' => $manage && ! $finalised,
                'finalise' => $manage && ! $finalised && $monthOver,
                'reopen' => $viewer->isSuperAdmin() && $finalised && $rows->every(fn (array $row) => $row['paid_at'] === null),
                'pay' => $manage && $finalised,
            ],
        ];
    }

    private function attempt(string $month, PayrollDesk $desk, Request $request, callable $action): JsonResponse
    {
        try {
            // Retried on a deadlock: two people starting the same month at once (PayrollDesk::draftRun).
            DB::transaction(fn () => $action(), 3);
        } catch (PayrollRefused $e) {
            return self::refused($e);
        }

        return response()->json(['data' => $this->payload($month, $desk, $request->user('staff'))]);
    }

    private function pdfResponse(PayrollItem $item, PayslipPdf $payslips, Request $request): HttpResponse
    {
        $locale = $request->query('locale') === 'en' || ($request->query('locale') === null && $item->staff->locale === 'en') ? 'en' : 'bn';

        return PortalDocumentController::inline($payslips->pdf($item, $locale), 'application/pdf', "payslip-{$item->run->month->format('Y-m')}-{$item->staff->employee_code}");
    }

    public static function refused(PayrollRefused $e): JsonResponse
    {
        $status = in_array($e->reason, ['super_admin_only', 'own_pay'], true) ? Response::HTTP_FORBIDDEN : Response::HTTP_CONFLICT;

        return response()->json(['message' => __("payroll.{$e->reason}"), 'code' => $e->reason], $status);
    }

    private function ensureManage(Request $request): void
    {
        abort_unless($request->user('staff')->can('payroll.manage'), Response::HTTP_FORBIDDEN, __('auth.forbidden'));
    }
}
