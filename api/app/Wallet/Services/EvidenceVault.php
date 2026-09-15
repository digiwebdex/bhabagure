<?php

namespace App\Wallet\Services;

use App\Wallet\Support\WalletCrypt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Wallet evidence (receipts, bank slips, screenshots): encrypted with WALLET_KEY into the wallet's own private directory,
 * so neither the company's storage code nor APP_KEY can read one.
 */
final class EvidenceVault
{
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public const MAX_KB = 5120;

    /** @return array<int, string> */
    public static function rules(): array
    {
        return ['nullable', 'file', 'mimes:'.implode(',', self::MIMES), 'max:'.self::MAX_KB];
    }

    /** @return array{path: string, mime: string, name: string} */
    public function put(UploadedFile $file): array
    {
        $path = trim((string) config('wallet.evidence.directory'), '/').'/'.Str::uuid().'.enc';
        Storage::disk((string) config('wallet.evidence.disk'))->put($path, WalletCrypt::encrypt((string) file_get_contents($file->getRealPath())));

        return ['path' => $path, 'mime' => (string) $file->getMimeType(), 'name' => mb_substr($file->getClientOriginalName(), 0, 160)];
    }

    public function read(string $path): string
    {
        return WalletCrypt::decrypt((string) Storage::disk((string) config('wallet.evidence.disk'))->get($path));
    }

    public function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::disk((string) config('wallet.evidence.disk'))->delete($path);
        }
    }
}
