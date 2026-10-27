<?php

namespace App\Console\Commands;

use App\Models\TourLandingPage;
use App\Services\Brochures\LandingBrochurePdf;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prints a landing page's brochure to a file for review (docs/tour-landing-pages.md §7), draft or not, whether or not
 * BROCHURE_LANDING_PDF is on: on the server's command line only. A draft is printed through the website's staff
 * preview, never from a public address. Nothing is stored for customers.
 */
class LandingBrochureCommand extends Command
{
    protected $signature = 'brochures:landing-pdf {slug : The landing page\'s address} {locale=bn : bn or en} {--out= : Where to write the PDF}';

    protected $description = 'Print a tour landing page\'s brochure to a file for review';

    public function handle(LandingBrochurePdf $brochures): int
    {
        $page = TourLandingPage::query()->where('slug', $this->argument('slug'))->first();
        if ($page === null) {
            $this->error('No landing page with that address.');

            return self::FAILURE;
        }
        $locale = $this->argument('locale') === 'en' ? 'en' : 'bn';
        $out = (string) ($this->option('out') ?: storage_path("app/private/tmp/landing-brochure-{$page->slug}-{$locale}.pdf"));

        try {
            $bytes = $brochures->render($page, $locale, preview: true);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        file_put_contents($out, $bytes);
        $this->info(sprintf('%s (%s, %s): %d KB → %s', $page->slug, $page->status->value, $locale, intdiv(strlen($bytes), 1024), $out));

        return self::SUCCESS;
    }
}
