<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Invoice PDFs from the print view with headless Chrome (packages/pdf/src/render.mjs). One render at a time — the VPS
 * is shared — and each result is kept on the private disk, keyed by everything that can change what's printed: the
 * invoice (its snapshot is frozen, so only payments and voiding change it), header on/off, language and template.
 */
class InvoicePdf
{
    public function __construct(private readonly InvoiceView $view) {}

    public function html(Invoice $invoice, bool $header, string $locale = 'bn', bool $maskPassports = false, bool $forPdf = false): string
    {
        return view('invoices.invoice', $this->view->data($invoice, $header, $locale, $maskPassports) + ['forPdf' => $forPdf])->render();
    }

    public function pdf(Invoice $invoice, bool $header, string $locale = 'bn', bool $maskPassports = false): string
    {
        $key = sha1(implode('|', [
            InvoiceView::TEMPLATE_VERSION, $invoice->id, $invoice->invoice_number, $invoice->status, $invoice->paid_amount,
            $invoice->payment_status, $invoice->updated_at?->getTimestamp(), (int) $header, $locale, (int) $maskPassports,
        ]));
        $path = "invoices/{$invoice->id}/{$key}.pdf";
        $disk = Storage::disk('local');
        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $bytes = Cache::lock('bhabaghure:invoice-pdf-render', 90)->block(75, fn () => $this->render($this->html($invoice, $header, $locale, $maskPassports, forPdf: true)));
        $disk->put($path, $bytes);

        return $bytes;
    }

    /** Renders any print-view HTML to PDF bytes. */
    public function render(string $html): string
    {
        $dir = storage_path('app/private/tmp/pdf');
        $chromeHome = (string) config('bhabaghure.invoices.chrome_home');
        foreach ([$dir, $chromeHome, "{$chromeHome}/tmp"] as $path) {
            if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
                throw new RuntimeException("Cannot create {$path}");
            }
        }
        $base = $dir.DIRECTORY_SEPARATOR.Str::random(24);
        file_put_contents("{$base}.html", $html);

        try {
            $process = new Process(
                [config('bhabaghure.invoices.node_binary'), config('bhabaghure.invoices.renderer'), "{$base}.html", "{$base}.pdf"],
                // Chrome's profile, caches and temp files stay inside the project's storage (shared VPS rule).
                env: array_filter([
                    'CHROME_PATH' => config('bhabaghure.invoices.chrome_path'),
                    'HOME' => $chromeHome,
                    'TMPDIR' => "{$chromeHome}/tmp",
                    'XDG_CONFIG_HOME' => "{$chromeHome}/.config",
                    'XDG_CACHE_HOME' => "{$chromeHome}/.cache",
                    // Windows only (a developer's machine): under PHP's built-in server (php artisan serve) a child process
                    // inherits almost none of the environment. Node can't seed its random number generator without
                    // SystemRoot, and Chrome's profile and PDF stream need TEMP.
                    'SystemRoot' => PHP_OS_FAMILY === 'Windows' ? getenv('SystemRoot') : null,
                    'TEMP' => PHP_OS_FAMILY === 'Windows' ? "{$chromeHome}/tmp" : null,
                    'TMP' => PHP_OS_FAMILY === 'Windows' ? "{$chromeHome}/tmp" : null,
                ]),
                timeout: (float) config('bhabaghure.invoices.timeout_seconds'),
            );
            $process->run();
            if (! $process->isSuccessful() || ! is_file("{$base}.pdf")) {
                throw new RuntimeException('Invoice PDF rendering failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
            }

            return (string) file_get_contents("{$base}.pdf");
        } finally {
            foreach (["{$base}.html", "{$base}.html.prepared.html", "{$base}.pdf"] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
