<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalPageVersion;
use App\Services\Cms\LegalPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website → Legal pages (docs/legal-pages.md): the booking terms, refund/cancellation policy and privacy policy. Saving
 * publishes a new version; earlier versions stay and can be read. Needs cms.manage, like the other website screens.
 */
class LegalPageController extends Controller
{
    /** The three pages, each with its current version (or none yet: the website still shows its built-in draft). */
    public function index(): JsonResponse
    {
        return response()->json(['data' => collect(LegalPages::SLUGS)->map(fn (string $slug) => [
            'slug' => $slug,
            'current' => ($current = LegalPages::current($slug)) ? self::summary($current) : null,
            'title_bn' => $current?->title_bn ?? LegalPages::startingText($slug)['title_bn'] ?? $slug,
        ])->all()]);
    }

    /** One page: what the editor starts from (the current version, or the built-in draft) and every version so far. */
    public function show(string $slug): JsonResponse
    {
        abort_unless(in_array($slug, LegalPages::SLUGS, true), 404);
        $current = LegalPages::current($slug);
        $versions = LegalPageVersion::query()->where('slug', $slug)->with('createdBy')->orderByDesc('version')->get();

        return response()->json(['data' => [
            'slug' => $slug,
            'published' => $current !== null,
            'text' => $current ? self::text($current) : LegalPages::startingText($slug),
            'versions' => $versions->map(self::summary(...))->all(),
        ]]);
    }

    public function version(string $slug, int $version): JsonResponse
    {
        $found = LegalPageVersion::query()->where('slug', $slug)->where('version', $version)->with('createdBy')->firstOrFail();

        return response()->json(['data' => self::summary($found) + self::text($found)]);
    }

    public function publish(Request $request, string $slug, LegalPages $pages): JsonResponse
    {
        abort_unless(in_array($slug, LegalPages::SLUGS, true), 404);
        $data = $request->validate([
            'title_bn' => ['required', 'string', 'max:160'],
            'title_en' => ['nullable', 'string', 'max:160'],
            'body_bn' => ['required', 'string', 'max:200000'],
            'body_en' => ['nullable', 'string', 'max:200000'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $version = $pages->publish($slug, $data, $request->user('staff'));

        return response()->json(['data' => self::summary($version->load('createdBy')) + self::text($version)], Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    private static function summary(LegalPageVersion $version): array
    {
        return [
            'version' => $version->version,
            'note' => $version->note,
            'published_at' => $version->created_at?->toIso8601String(),
            'published_by' => $version->createdBy?->name,
        ];
    }

    /** @return array{title_bn: string, title_en: ?string, body_bn: string, body_en: ?string} */
    private static function text(LegalPageVersion $version): array
    {
        return ['title_bn' => $version->title_bn, 'title_en' => $version->title_en, 'body_bn' => $version->body_bn, 'body_en' => $version->body_en];
    }
}
