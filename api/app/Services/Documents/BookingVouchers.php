<?php

namespace App\Services\Documents;

use App\Models\Booking;
use App\Models\BookingVoucher;
use App\Models\BookingVoucherFile;
use App\Models\Staff;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
    /** PDF, JPG/JPEG or PNG (PNG added 2026-10-03); up to 10 MB (a scanned multi-page contract). */
    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png'];

    public const MAX_KB = 10240;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<string> */
    public static function fileRules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'file', 'mimes:'.implode(',', self::MIMES), 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.self::MAX_KB];
    }

    /** @param array{title: string, service_date: ?string} $data */
    public function upload(array $data, ?Booking $booking, UploadedFile $file, Staff $by): BookingVoucher
    {
        $stored = $this->store($file);

        try {
            return DB::transaction(function () use ($data, $booking, $by, $stored) {
                $voucher = BookingVoucher::query()->create([
                    'title' => trim($data['title']),
                    'booking_id' => $booking?->id,
                    'service_date' => $data['service_date'] ?? null,
                    ...$stored,
                    'uploaded_by_staff_id' => $by->id,
                ]);
                $this->audit->record('voucher.uploaded', $by, $voucher, ['booking_id' => $booking?->id]);

                return $voucher;
            });
        } catch (Throwable $e) {
            Storage::disk(TravellerDocuments::DISK)->delete($stored['path']);
            throw $e;
        }
    }

    /**
     * Edits a voucher (docs/booking-vouchers.md §6): its title, booking and service date, and with a new file, the file.
     * The file it had is kept as an earlier file, still encrypted where it was, so a wrong replacement can be undone. An
     * archived voucher is not edited.
     *
     * @param  array{title: string, service_date: ?string}  $data
     *
     * @throws DocumentRefused
     */
    public function update(BookingVoucher $voucher, array $data, ?Booking $booking, ?UploadedFile $file, Staff $by): BookingVoucher
    {
        $stored = $file ? $this->store($file) : null;

        try {
            return DB::transaction(function () use ($voucher, $data, $booking, $by, $stored) {
                $locked = BookingVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
                if ($locked->archived_at !== null) {
                    throw new DocumentRefused('voucher_archived');
                }

                $before = ['title' => $locked->title, 'booking_id' => $locked->booking_id, 'service_date' => $locked->service_date?->toDateString()];
                $locked->fill(['title' => trim($data['title']), 'booking_id' => $booking?->id, 'service_date' => $data['service_date'] ?? null]);
                $after = ['title' => $locked->title, 'booking_id' => $locked->booking_id, 'service_date' => $locked->service_date?->toDateString()];
                $changes = collect($after)->filter(fn ($value, string $field) => $value !== $before[$field])
                    ->map(fn ($value, string $field) => ['from' => $before[$field], 'to' => $value])->all();

                if ($stored !== null) {
                    // Who put the current file there: whoever replaced the one before it, or the voucher's uploader.
                    $previous = $locked->earlierFiles()->first();
                    $locked->earlierFiles()->create([
                        'disk' => $locked->disk,
                        'path' => $locked->path,
                        'mime' => $locked->mime,
                        'bytes' => $locked->bytes,
                        'original_name' => $locked->original_name,
                        'uploaded_by_staff_id' => $previous?->replaced_by_staff_id ?? $locked->uploaded_by_staff_id,
                        'uploaded_at' => $locked->file_uploaded_at ?? $locked->created_at,
                        'replaced_by_staff_id' => $by->id,
                    ]);
                    $changes['file'] = ['from' => $locked->original_name, 'to' => $stored['original_name']];
                    $locked->fill($stored);
                }

                $locked->save();
                if ($changes !== []) {
                    $this->audit->record('voucher.updated', $by, $locked, $changes);
                }

                return $locked;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                Storage::disk(TravellerDocuments::DISK)->delete($stored['path']);
            }
            throw $e;
        }
    }

    /**
     * The file, encrypted on the private disk, and what the voucher records about it.
     *
     * @return array{disk: string, path: string, mime: string, bytes: int, original_name: string, file_uploaded_at: Carbon}
     */
    private function store(UploadedFile $file): array
    {
        $path = sprintf('booking-vouchers/%s/%s.enc', now('Asia/Dhaka')->format('Y/m'), Str::uuid());
        Storage::disk(TravellerDocuments::DISK)->put($path, Crypt::encryptString((string) file_get_contents($file->getRealPath())));

        return [
            'disk' => TravellerDocuments::DISK,
            'path' => $path,
            'mime' => Str::limit((string) $file->getMimeType(), 60, ''),
            'bytes' => (int) $file->getSize(),
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 180, ''),
            'file_uploaded_at' => now(),
        ];
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

    /** The voucher's file, or one of its earlier files, decrypted. */
    public function contents(BookingVoucher|BookingVoucherFile $file): string
    {
        return Crypt::decryptString((string) Storage::disk($file->disk)->get($file->path));
    }
}
