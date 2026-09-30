<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Media\HeroVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin → Site settings → Home page video (client, 2026-10-01; docs/hero-video.md). cms.manage, like the other settings.
 */
class HeroVideoController extends Controller
{
    public function __construct(private readonly HeroVideo $hero) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => HeroVideo::present(), 'meta' => ['maxBytes' => HeroVideo::MAX_BYTES, 'chunkBytes' => HeroVideo::chunkBytes()]]);
    }

    /** One piece of an upload, in order; the last one is followed by publish(). */
    public function chunk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload' => ['required', 'uuid'],
            'index' => ['required', 'integer', 'min:0', 'max:'.intdiv(HeroVideo::MAX_BYTES - 1, HeroVideo::chunkBytes())],
            'size' => ['required', 'integer', 'min:1', 'max:'.HeroVideo::MAX_BYTES],
            'type' => ['required', Rule::in(array_keys(HeroVideo::TYPES))],
            'name' => ['required', 'string', 'max:200'],
            'chunk' => ['required', 'file', 'max:'.intdiv(HeroVideo::chunkBytes(), 1024)],
        ], ['size.max' => __('validation.max.file', ['attribute' => 'video', 'max' => intdiv(HeroVideo::MAX_BYTES, 1024)])]);

        $received = $this->hero->appendChunk($data['upload'], (int) $data['index'], (int) $data['size'], $data['type'], $data['name'], $request->file('chunk'));

        return response()->json(['data' => ['received' => $received]]);
    }

    /** The finished upload goes on the home page, with the poster the admin took from its first second. */
    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload' => ['required', 'uuid'],
            'poster' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        return response()->json(['data' => $this->hero->publishUpload($data['upload'], $request->file('poster'), $request->user('staff'))]);
    }

    /** A direct https link to an MP4 or WebM file, with an optional poster. */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:https', 'max:1000'],
            'poster' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        return response()->json(['data' => $this->hero->publishLink($data['url'], $request->file('poster'), $request->user('staff'))]);
    }

    /** Back to the video the website ships with. */
    public function destroy(Request $request): Response
    {
        $this->hero->restore($request->user('staff'));

        return response()->noContent();
    }
}
