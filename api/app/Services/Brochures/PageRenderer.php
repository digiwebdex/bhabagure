<?php

namespace App\Services\Brochures;

use App\Services\Invoices\InvoicePdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Prints one website page to PDF with packages/pdf/src/render-page.mjs (docs/tour-landing-pages.md §7). The renderer
 * loads exactly what the manifest allows — the page, the website's own static files, the photos prepared as files — and
 * nothing else; it follows no redirect. Any failure throws, and no PDF comes back.
 */
class PageRenderer
{
    /**
     * @param  array{url: string, headers?: array<string, string>, staticPrefix: string, assets: array<string, string>}  $manifest
     * @return string the PDF's bytes
     */
    public function render(array $manifest): string
    {
        $dir = storage_path('app/private/tmp/pdf');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        $base = $dir.DIRECTORY_SEPARATOR.Str::random(24);
        file_put_contents("{$base}.json", json_encode($manifest + [
            'pageTimeoutMs' => (int) config('bhabaghure.brochures.page_timeout_ms'),
            'assetsTimeoutMs' => (int) config('bhabaghure.brochures.assets_timeout_ms'),
        ], JSON_UNESCAPED_SLASHES));

        try {
            $process = new Process(
                [config('bhabaghure.invoices.node_binary'), config('bhabaghure.brochures.page_renderer'), "{$base}.json", "{$base}.pdf"],
                env: InvoicePdf::rendererEnvironment(),
                timeout: (float) config('bhabaghure.invoices.timeout_seconds'),
            );
            // One Chrome at a time on the shared server, as for invoices.
            Cache::lock('bhabaghure:invoice-pdf-render', 90)->block(75, fn () => $process->run());
            if (! $process->isSuccessful() || ! is_file("{$base}.pdf")) {
                throw new RuntimeException('Landing page PDF failed: '.Str::limit(trim($process->getOutput()."\n".$process->getErrorOutput()), 2000));
            }

            return (string) file_get_contents("{$base}.pdf");
        } finally {
            foreach (["{$base}.json", "{$base}.pdf"] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
