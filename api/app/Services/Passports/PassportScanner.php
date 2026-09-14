<?php

namespace App\Services\Passports;

use App\Models\PassportScan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores an uploaded passport scan encrypted on the private disk and reads its MRZ (docs/phase-3-booking.md §5).
 * The result is a suggestion for the traveller to check: status `read` (every check digit passed), `partial` (some
 * fields to confirm) or `unavailable` (no provider, provider error, or no MRZ found) — then fields are typed by hand.
 */
final class PassportScanner
{
    public function __construct(
        private readonly PassportTextReader $reader,
        private readonly MrzParser $parser,
    ) {}

    /** @return array{token: string, status: string, provider: string, fields: ?array<string, mixed>} */
    public function scan(UploadedFile $file, ?string $ip): array
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = (string) $file->getMimeType();
        $token = Str::random(48);
        $disk = (string) config('bhabaghure.passport_ocr.disk');
        $path = 'passport-scans/'.now()->format('Y/m').'/'.Str::uuid().'.enc';

        // Encrypted with APP_KEY before it touches the disk; never under the public disk.
        Storage::disk($disk)->put($path, Crypt::encryptString($bytes));

        $text = $this->reader->read($bytes, $mime);
        $fields = $text === null ? null : $this->parser->parse($text);
        $status = match (true) {
            $fields === null => 'unavailable',
            $fields['compositeValid'] && ! $fields['passportNumber']['confirm'] && ! $fields['dateOfBirth']['confirm'] && ! $fields['passportExpiry']['confirm'] => 'read',
            default => 'partial',
        };

        PassportScan::query()->create([
            'token_hash' => PassportScan::hashToken($token),
            'disk' => $disk,
            'path' => $path,
            'mime' => Str::limit($mime, 60, ''),
            'bytes' => strlen($bytes),
            'ocr_status' => $status,
            'ocr_provider' => $this->reader->name(),
            'expires_at' => now()->addHours((int) config('bhabaghure.passport_ocr.retention_hours')),
            'ip' => $ip,
        ]);

        return ['token' => $token, 'status' => $status, 'provider' => $this->reader->name(), 'fields' => $fields];
    }

    /** Scans no booking used, past their retention window. Scheduled hourly. */
    public function prune(): int
    {
        $count = 0;
        PassportScan::query()->whereNull('booking_traveller_id')->where('expires_at', '<', now())->orderBy('id')
            ->chunkById(200, function ($scans) use (&$count) {
                foreach ($scans as $scan) {
                    Storage::disk($scan->disk)->delete($scan->path);
                    $scan->delete();
                    $count++;
                }
            });

        return $count;
    }
}
