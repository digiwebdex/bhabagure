<?php

namespace App\Services\Hr;

use App\Models\Staff;
use App\Models\StaffDocument;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Staff documents in the Vault (docs/phase-7-hr-attendance-bonus-wallet.md §4.2), protected as customer passports are:
 * the file is encrypted with APP_KEY before it reaches the private disk and the number is an encrypted column. One step
 * further than passports: every time someone opens a file is audited. Nothing is deleted; a replacement archives the
 * document it replaces. The audit log names documents and people, never numbers or contents.
 */
final class StaffDocuments
{
    public const DISK = 'local';

    public const MAX_KB = 5120;

    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<string> */
    public static function fileRules(): array
    {
        return ['required', 'file', 'mimes:'.implode(',', self::MIMES), 'max:'.self::MAX_KB];
    }

    /**
     * Files a new document for $owner, or — with $replacing — its replacement, archiving the old one.
     *
     * @param  array{type: string, title?: ?string, number?: ?string, issued_on?: ?string, expires_on?: ?string, note?: ?string}  $data
     *
     * @throws HrRefused
     */
    public function upload(Staff $owner, array $data, UploadedFile $file, Staff $by, ?StaffDocument $replacing = null): StaffDocument
    {
        $path = sprintf('staff-documents/%s/%s.enc', now('Asia/Dhaka')->format('Y/m'), Str::uuid());
        Storage::disk(self::DISK)->put($path, Crypt::encryptString((string) file_get_contents($file->getRealPath())));

        try {
            return DB::transaction(function () use ($owner, $data, $file, $by, $replacing, $path) {
                $old = null;
                if ($replacing !== null) {
                    $old = StaffDocument::query()->whereKey($replacing->id)->lockForUpdate()->firstOrFail();
                    if ($old->archived_at !== null) {
                        throw new HrRefused('document_archived');
                    }
                }

                $document = StaffDocument::query()->create([
                    'staff_id' => $owner->id,
                    'type' => $data['type'],
                    'title' => $data['title'] ?? null,
                    'number' => $data['number'] ?? null,
                    'issued_on' => $data['issued_on'] ?? null,
                    'expires_on' => $data['expires_on'] ?? null,
                    'note' => $data['note'] ?? null,
                    'disk' => self::DISK,
                    'path' => $path,
                    'mime' => Str::limit((string) $file->getMimeType(), 60, ''),
                    'bytes' => (int) $file->getSize(),
                    'uploaded_by_staff_id' => $by->id,
                ]);

                if ($old !== null) {
                    $old->forceFill(['archived_at' => now(), 'archived_by_staff_id' => $by->id, 'archive_reason' => 'replaced', 'replaced_by_id' => $document->id])->save();
                    $this->audit->record('staff_document.replaced', $by, $owner, ['document_id' => $document->id, 'replaced_id' => $old->id, 'type' => $document->type]);
                } else {
                    $this->audit->record('staff_document.uploaded', $by, $owner, ['document_id' => $document->id, 'type' => $document->type]);
                }

                return $document;
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }
    }

    /** Withdraws a document with a reason. It stays listed under archived, and its file can still be opened. @throws HrRefused */
    public function archive(StaffDocument $document, string $reason, Staff $by): StaffDocument
    {
        return DB::transaction(function () use ($document, $reason, $by) {
            $locked = StaffDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->archived_at !== null) {
                throw new HrRefused('document_archived');
            }
            $locked->forceFill(['archived_at' => now(), 'archived_by_staff_id' => $by->id, 'archive_reason' => $reason])->save();
            $this->audit->record('staff_document.archived', $by, $locked->staff()->withTrashed()->first(), ['document_id' => $locked->id, 'type' => $locked->type, 'reason' => $reason]);

            return $locked;
        });
    }

    /** The decrypted file; the opening is audited first. */
    public function open(StaffDocument $document, Staff $by): string
    {
        $this->audit->record('staff_document.opened', $by, $document->staff()->withTrashed()->first(), ['document_id' => $document->id, 'type' => $document->type]);

        return Crypt::decryptString((string) Storage::disk($document->disk)->get($document->path));
    }
}
