<?php

namespace App\Services\Ledger;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Receipts, bank slips and bKash screenshots for cash-book rows (docs/phase-5-admin-core.md §4.6). On the private disk
 * only — never the public media pipeline — and served through an authenticated route. A cash-book row is append-only,
 * so the file is stored first and its path written with the row; if the row isn't written, the file is removed.
 */
final class EvidenceStore
{
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public const MAX_KB = 10240;

    public function put(UploadedFile $file): string
    {
        $extension = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
        $path = sprintf('evidence/%s/%s.%s', now('Asia/Dhaka')->format('Y/m'), Str::uuid(), $extension);
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return $path;
    }

    public function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Stores the file, runs $write with its path, and removes the file again if $write fails.
     *
     * @template T
     *
     * @param  callable(?string): T  $write
     * @return T
     */
    public function with(?UploadedFile $file, callable $write): mixed
    {
        $path = $file ? $this->put($file) : null;
        try {
            return $write($path);
        } catch (\Throwable $e) {
            $this->forget($path);
            throw $e;
        }
    }
}
