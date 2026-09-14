<?php

namespace App\Services\Documents;

use App\Models\BookingTraveller;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\TravellerDocument;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Traveller documents (docs/phase-6-customer-portal.md §3.3, §5). Uploads are encrypted with APP_KEY before they reach
 * the private disk and are served back only to the booking's customer and to staff who can see the booking. The audit
 * log records who uploaded, verified or rejected — never file contents or passport digits.
 */
final class TravellerDocuments
{
    public const DISK = 'local';

    public const MAX_KB = 5120;

    /** A passport scan may be a PDF; a photo is an image. */
    public const MIMES = [TravellerDocument::PASSPORT_SCAN => ['jpg', 'jpeg', 'png', 'webp', 'pdf'], TravellerDocument::PHOTO => ['jpg', 'jpeg', 'png', 'webp']];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<string> */
    public static function rules(string $kind): array
    {
        return ['required', 'file', 'mimes:'.implode(',', self::MIMES[$kind]), 'max:'.self::MAX_KB];
    }

    /**
     * The four slots, in order. A missing upload is `missing`; visa and insurance nobody has set yet are `pending`.
     *
     * @return list<array{kind: string, status: string, note: ?string, uploadedAt: ?string, reviewedAt: ?string, hasFile: bool}>
     */
    public static function slots(BookingTraveller $traveller): array
    {
        $rows = $traveller->relationLoaded('documents') ? $traveller->documents : $traveller->documents()->get();

        return array_map(function (string $kind) use ($rows) {
            /** @var TravellerDocument|null $row */
            $row = $rows->firstWhere('kind', $kind);

            return [
                'kind' => $kind,
                'status' => $row?->status ?? (in_array($kind, TravellerDocument::UPLOADS, true) ? 'missing' : TravellerDocument::PENDING),
                'note' => $row?->note,
                'uploadedAt' => $row?->uploaded_at?->toIso8601String(),
                'reviewedAt' => $row?->reviewed_at?->toIso8601String(),
                'hasFile' => $row?->path !== null,
            ];
        }, TravellerDocument::KINDS);
    }

    /**
     * Stores a new passport scan or photo, replacing one still waiting or rejected. A verified document stays: staff
     * already checked it against the passport.
     *
     * @throws DocumentRefused
     */
    public function upload(BookingTraveller $traveller, string $kind, UploadedFile $file, Customer|Staff $by): TravellerDocument
    {
        $path = sprintf('traveller-documents/%s/%s.enc', now('Asia/Dhaka')->format('Y/m'), Str::uuid());
        Storage::disk(self::DISK)->put($path, Crypt::encryptString((string) file_get_contents($file->getRealPath())));

        try {
            [$document, $previous] = DB::transaction(function () use ($traveller, $kind, $file, $by, $path) {
                $existing = TravellerDocument::query()->where('booking_traveller_id', $traveller->id)->where('kind', $kind)->lockForUpdate()->first();
                if ($existing?->status === TravellerDocument::VERIFIED) {
                    throw new DocumentRefused('already_verified');
                }
                $previous = $existing ? ['disk' => $existing->disk, 'path' => $existing->path] : null;

                $document = $existing ?? new TravellerDocument(['booking_traveller_id' => $traveller->id, 'kind' => $kind]);
                $document->fill([
                    'status' => TravellerDocument::UPLOADED, 'disk' => self::DISK, 'path' => $path, 'mime' => Str::limit((string) $file->getMimeType(), 60, ''),
                    'bytes' => (int) $file->getSize(), 'note' => null, 'source' => $by instanceof Staff ? 'staff' : 'portal', 'uploaded_at' => now(),
                    'reviewed_by_staff_id' => null, 'reviewed_at' => null,
                ])->save();
                $this->audit->record('traveller_document.uploaded', $by, $traveller->loadMissing('booking')->booking, ['traveller_id' => $traveller->id, 'kind' => $kind]);

                return [$document, $previous];
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }

        // The replaced file isn't needed once the new row points at the new one. The website booking's scan is shared
        // with its passport_scans row, so only files this service wrote are removed.
        if ($previous !== null && $previous['path'] !== null && str_starts_with($previous['path'], 'traveller-documents/')) {
            Storage::disk($previous['disk'] ?? self::DISK)->delete($previous['path']);
        }

        return $document;
    }

    /** @throws DocumentRefused */
    public function review(TravellerDocument $document, bool $verified, ?string $reason, Staff $staff): TravellerDocument
    {
        return DB::transaction(function () use ($document, $verified, $reason, $staff) {
            $locked = TravellerDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->kind, TravellerDocument::UPLOADS, true) || $locked->status !== TravellerDocument::UPLOADED) {
                throw new DocumentRefused('not_waiting');
            }
            $locked->fill([
                'status' => $verified ? TravellerDocument::VERIFIED : TravellerDocument::REJECTED,
                'note' => $verified ? null : $reason,
                'reviewed_by_staff_id' => $staff->id, 'reviewed_at' => now(),
            ])->save();
            $locked->loadMissing('traveller.booking');
            $this->audit->record($verified ? 'traveller_document.verified' : 'traveller_document.rejected', $staff, $locked->traveller->booking,
                array_filter(['traveller_id' => $locked->booking_traveller_id, 'kind' => $locked->kind, 'reason' => $verified ? null : $reason]));

            return $locked;
        });
    }

    /** Visa or insurance: pending, issued or not required, with a note the customer sees. */
    public function setIssued(BookingTraveller $traveller, string $kind, string $status, ?string $note, Staff $staff): TravellerDocument
    {
        return DB::transaction(function () use ($traveller, $kind, $status, $note, $staff) {
            $document = TravellerDocument::query()->where('booking_traveller_id', $traveller->id)->where('kind', $kind)->lockForUpdate()->first()
                ?? new TravellerDocument(['booking_traveller_id' => $traveller->id, 'kind' => $kind]);
            $document->fill(['status' => $status, 'note' => $note, 'source' => 'staff', 'reviewed_by_staff_id' => $staff->id, 'reviewed_at' => now()])->save();
            $this->audit->record('traveller_document.status_set', $staff, $traveller->loadMissing('booking')->booking,['traveller_id' => $traveller->id, 'kind' => $kind, 'status' => $status]);

            return $document;
        });
    }

    /**
     * A passport number the customer adds for a traveller who has none on file. Once one is on file only staff change it,
     * and it is never shown back in the portal.
     *
     * @throws DocumentRefused
     */
    public function addPassportNumber(BookingTraveller $traveller, string $number, string $expiry, Customer $customer): void
    {
        DB::transaction(function () use ($traveller, $number, $expiry, $customer) {
            $locked = BookingTraveller::query()->whereKey($traveller->id)->lockForUpdate()->firstOrFail();
            if (filled($locked->passport_number)) {
                throw new DocumentRefused('passport_on_file');
            }
            $locked->forceFill(['passport_number' => $number, 'passport_expiry' => $expiry])->save();
            $this->audit->record('traveller.passport_added', $customer, $locked->loadMissing('booking')->booking,['traveller_id' => $locked->id]);
        });
    }

    /** The decrypted file. */
    public function contents(TravellerDocument $document): string
    {
        return Crypt::decryptString((string) Storage::disk($document->disk ?? self::DISK)->get((string) $document->path));
    }
}
