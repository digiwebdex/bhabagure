<?php

namespace App\Services\Payroll;

use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Services\Invoices\InvoicePdf;
use App\Services\Invoices\InvoiceView;
use Illuminate\Support\Facades\Cache;

/**
 * A payslip for one person's finalised month (docs/phase-7-hr-attendance-bonus-wallet.md §6), on the invoices'
 * letterhead and headless-Chrome renderer. Rendered on demand and never cached on disk: it states a salary.
 */
class PayslipPdf
{
    public function __construct(private readonly InvoicePdf $renderer, private readonly InvoiceView $invoices) {}

    public function html(PayrollItem $item, string $locale, bool $forPdf = false): string
    {
        $item->loadMissing(['staff.profile', 'run', 'transaction']);
        $adjustments = PayrollAdjustment::query()->where('payroll_run_id', $item->payroll_run_id)->where('staff_id', $item->staff_id)->orderBy('id')->get();

        return view('payroll.payslip', $this->invoices->letterhead($locale) + [
            'locale' => $locale,
            'forPdf' => $forPdf,
            'item' => $item,
            'month' => $item->run->month,
            'rules' => $item->run->rules ?? [],
            'adjustments' => $adjustments,
        ])->render();
    }

    public function pdf(PayrollItem $item, string $locale): string
    {
        // The same lock as invoices: one Chrome at a time on the shared server.
        return Cache::lock('bhabaghure:invoice-pdf-render', 90)->block(75, fn () => $this->renderer->render($this->html($item, $locale, forPdf: true)));
    }
}
