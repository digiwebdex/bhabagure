<?php

namespace App\Services\Documents;

use App\Models\Booking;
use App\Models\BookingVoucher;
use App\Models\Staff;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Suppliers' confirmation vouchers and contracts (docs/booking-vouchers.md). The file is encrypted on the private disk,
 * as e-tickets are, and served only through the API to staff who may see vouchers. A voucher is archived with a reason,
 * never deleted, so what was confirmed is never lost.
 */
final class BookingVouchers
{
    /** PDF or JPG/JPEG, as asked; up to 10 MB (a scanned multi-page contract). */
    public const MIMES = ['pdf', 'jpg', 'jpeg'];

    public const MAX_KB = 10240;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<string> */
    public static function fileRules(): array
    {
        return ['required', 'file', 'mimes:'.implode(',', self::MIMES), 'mimetypes:application/pdf,image/jpeg', 'max:'.self::MAX_KB];
    }

    /** @param array{title: string, service_date: ?string} $data */
    public function upload(array $data, ?Booking $booking, UploadedFile $file, Staff $by): BookingVoucher
    {
        $path = sprintf('booking-vouchers/%s/%s.enc', now('Asia/Dhaka')->format('Y/m'), Str::uuid());
        Storage::disk(TravellerDocuments::DISK)->put($path, Crypt::encryptString((string) file_get_contents($file->getRealPath())));

        try {
            return DB::transaction(function () use ($data, $booking, $file, $by, $path) {
                $voucher = BookingVoucher::query()->create([
                    'title' => trim($data['title']),
                    'booking_id' => $booking?->id,
                    'service_date' => $data['service_date'] ?? null,
                    'disk' => TravellerDocuments::DISK,
                    'path' => $path,
                    'mime' => Str::limit((string) $file->getMimeType(), 60, ''),
                    'bytes' => (int) $file->getSize(),
                    'original_name' => Str::limit(basename($file->getClientOriginalName()), 180, ''),
                    'uploaded_by_staff_id' => $by->id,
                ]);
                $this->audit->record('voucher.uploaded', $by, $voucher, ['booking_id' => $booking?->id]);

                return $voucher;
            });
        } catch (Throwable $e) {
            Storage::disk(TravellerDocuments::DISK)->delete($path);
            throw $e;
        }
    }

    /** @throws DocumentRefused */
    public function archive(BookingVoucher $voucher, string $reason, Staff $by): BookingVoucher
    {
        return DB::transaction(function () use ($voucher, $reason, $by) {
            $locked = BookingVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($locked->archived_at !== null) {
                throw new DocumentRefused('voucher_archived');
            }
            $locked->forceFill(['archived_at' => now(), 'archived_by_staff_id' => $by->id, 'archive_reason' => $reason])->save();
            $this->audit->record('voucher.archived', $by, $locked, ['reason' => $reason]);

            return $locked;
        });
    }

    public function contents(BookingVoucher $voucher): string
    {
        return Crypt::decryptString((string) Storage::disk($voucher->disk)->get($voucher->path));
    }
}
