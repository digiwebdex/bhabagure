<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The office agent's install package (client, 2026-10-03): the zip an admin downloads from the Attendance screen on the
 * office PC, unzips and installs with a double-click. It lives in storage/app/private/attendance-agent/ on the server
 * beside its manifest, latest.json, both put there by the developer with deploy/upload-agent-package.sh: the build is
 * never committed to the public repository (docs/phase-7-hr-attendance-bonus-wallet.md §5.2), so no deploy brings it.
 *
 * Only a file name of the package's own pattern, for the version the manifest states, is believed — a manifest can't
 * point outside the folder — and only once the zip is there at the size the manifest gives, so a half-finished upload
 * is never handed out.
 */
final class AgentPackage
{
    public const FOLDER = 'attendance-agent';

    public const MANIFEST = self::FOLDER.'/latest.json';

    public const FILE_PREFIX = 'BhabaghureAttendanceAgent-';

    /**
     * The package on the server, as latest.json describes it; null when there is none, or it doesn't hold together.
     *
     * @return array{version: string, filename: string, bytes: int, sha256: string, uploaded_at: string}|null
     */
    public function manifest(): ?array
    {
        $disk = self::disk();
        try {
            $data = json_decode((string) $disk->get(self::MANIFEST), true, 8, JSON_THROW_ON_ERROR);
            $uploadedAt = CarbonImmutable::parse((string) ($data['uploaded_at'] ?? ''))->toIso8601String();
        } catch (Throwable) {
            return null;
        }

        $version = $data['version'] ?? null;
        // \z, not $: a version ending in a line break would otherwise pass, and reach a header.
        $valid = is_string($version) && preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}\z/', $version) === 1
            && ($data['filename'] ?? null) === self::FILE_PREFIX.$version.'.zip'
            && is_int($data['bytes'] ?? null) && $data['bytes'] > 0
            && is_string($data['sha256'] ?? null) && preg_match('/^[0-9a-f]{64}\z/', $data['sha256']) === 1
            && filled($data['uploaded_at'] ?? null);
        if (! $valid) {
            return null;
        }
        $path = self::FOLDER.'/'.$data['filename'];
        if (! $disk->exists($path) || $disk->size($path) !== $data['bytes']) {
            return null;
        }

        return ['version' => $version, 'filename' => $data['filename'], 'bytes' => $data['bytes'], 'sha256' => $data['sha256'], 'uploaded_at' => $uploadedAt];
    }

    /**
     * The zip as an attachment, streamed from the disk, never cached.
     *
     * @param  array{filename: string}  $manifest  from manifest()
     */
    public function download(array $manifest): StreamedResponse
    {
        return self::disk()->download(self::FOLDER.'/'.$manifest['filename'], $manifest['filename'], [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$manifest['filename'].'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }
}
