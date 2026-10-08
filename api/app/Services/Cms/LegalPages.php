<?php

namespace App\Services\Cms;

use App\Jobs\RevalidateWebsite;
use App\Models\LegalPageVersion;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The booking terms, refund/cancellation policy and privacy policy (docs/legal-pages.md). Each publish is a new version;
 * the website shows the latest, and a booking records which terms version the customer accepted. Before the first
 * publish the website shows its built-in draft, and the admin starts from that draft's text.
 */
final class LegalPages
{
    public const SLUGS = ['terms', 'refund-policy', 'privacy'];

    /** The booking-terms version a customer accepts before anything is published: the website's built-in draft. */
    public const DRAFT_TERMS_VERSION = 'draft-2026-09';

    public function __construct(private readonly AuditLogger $audit) {}

    public static function current(string $slug): ?LegalPageVersion
    {
        return LegalPageVersion::query()->where('slug', $slug)->orderByDesc('version')->first();
    }

    /** What a booking records as accepted: "terms-v3" once the terms are published, the draft's name before that. */
    public static function termsVersion(): string
    {
        $current = self::current('terms');

        return $current === null ? (string) config('bhabaghure.booking.terms_version', self::DRAFT_TERMS_VERSION) : "terms-v{$current->version}";
    }

    /**
     * The draft the website shows before the first publish, as editor HTML (packages/content-seed/legal.json).
     *
     * @return array{title_bn: string, title_en: string, body_bn: string, body_en: string}|null
     */
    public static function startingText(string $slug): ?array
    {
        $path = rtrim((string) config('bhabaghure.content_seed_path'), '/\\').DIRECTORY_SEPARATOR.'legal.json';
        $all = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $page = $all[$slug] ?? null;

        return is_array($page) ? [
            'title_bn' => $page['title']['bn'], 'title_en' => $page['title']['en'],
            'body_bn' => $page['body']['bn'], 'body_en' => $page['body']['en'],
        ] : null;
    }

    /** @param array{title_bn: string, title_en?: ?string, body_bn: string, body_en?: ?string, note?: ?string} $data */
    public function publish(string $slug, array $data, Staff $by): LegalPageVersion
    {
        if (! in_array($slug, self::SLUGS, true)) {
            throw new RuntimeException("Unknown legal page {$slug}");
        }

        $version = DB::transaction(function () use ($slug, $data, $by) {
            // The newest version locked, so two publishes at once can't take the same number.
            $last = LegalPageVersion::query()->where('slug', $slug)->lockForUpdate()->max('version') ?? 0;
            $version = LegalPageVersion::query()->create([
                'slug' => $slug,
                'version' => $last + 1,
                'title_bn' => trim($data['title_bn']),
                'title_en' => filled($data['title_en'] ?? null) ? trim($data['title_en']) : null,
                'body_bn' => (string) HtmlSanitizer::clean($data['body_bn']),
                'body_en' => HtmlSanitizer::clean($data['body_en'] ?? null),
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'created_by_staff_id' => $by->id,
            ]);
            $this->audit->record('legal_page.published', $by, $version, ['slug' => $slug, 'version' => $version->version]);

            return $version;
        });
        RevalidateWebsite::dispatch(['legal']);

        return $version;
    }
}
