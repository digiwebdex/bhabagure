<?php

namespace App\Jobs;

use App\Mail\PayslipMail;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Services\Payroll\PayslipPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails each person their payslip when a month is finalised (docs/phase-7-hr-attendance-bonus-wallet.md §6). Queued:
 * one PDF render each, one at a time. A failure for one person is logged and doesn't stop the others; everyone can also
 * download theirs from My attendance.
 */
class SendPayslips implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public readonly int $payrollRunId) {}

    public function handle(PayslipPdf $payslips): void
    {
        $run = PayrollRun::query()->find($this->payrollRunId);
        if ($run === null || $run->status !== PayrollRun::FINALISED) {
            return;
        }

        $run->items()->with(['staff', 'run'])->orderBy('id')->each(function (PayrollItem $item) use ($payslips) {
            if (blank($item->staff->email)) {
                return;
            }
            $locale = $item->staff->locale === 'en' ? 'en' : 'bn';
            try {
                Mail::to($item->staff->email)->send(new PayslipMail($item, $payslips->pdf($item, $locale), $locale));
            } catch (Throwable $e) {
                Log::warning('Payslip email failed', ['payroll_item_id' => $item->id, 'error' => $e->getMessage()]);
            }
        });
    }
}
