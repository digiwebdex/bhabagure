<?php

namespace App\Models;

use App\Enums\StaffStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff document in the Vault (docs/phase-7-hr-attendance-bonus-wallet.md §4.2): protected like a customer passport —
 * number encrypted, file encrypted on the private disk — and seen only with `staff_documents.view`. Replaced or withdrawn
 * documents are archived with a reason, never deleted. The status is derived from the expiry date, in Dhaka.
 */
class StaffDocument extends Model
{
    public const TYPES = ['passport', 'nid', 'driving_licence', 'cv', 'appointment_letter', 'contract', 'certificate', 'photo', 'other'];

    public const EXPIRED = 'expired';

    public const EXPIRING = 'expiring';

    public const RENEW_SOON = 'renew_soon';

    public const VALID = 'valid';

    public const NO_EXPIRY = 'no_expiry';

    /** Expired or expiring, for staff who aren't suspended: the Vault badge and the expiry alerts. */
    public const ATTENTION = 'attention';

    public const STATUSES = [self::EXPIRED, self::EXPIRING, self::RENEW_SOON, self::VALID, self::NO_EXPIRY];

    /** Days before the expiry date. To be checked against the re-synced design. */
    public const EXPIRING_DAYS = 30;

    public const RENEW_SOON_DAYS = 90;

    protected $fillable = [
        'staff_id', 'type', 'title', 'number', 'issued_on', 'expires_on', 'disk', 'path', 'mime', 'bytes', 'note',
        'uploaded_by_staff_id', 'archived_at', 'archived_by_staff_id', 'archive_reason', 'replaced_by_id',
    ];

    protected $hidden = ['disk', 'path', 'number_hash'];

    protected function casts(): array
    {
        return [
            // AES-256 with APP_KEY, as passport numbers.
            'number' => 'encrypted',
            'issued_on' => 'date',
            'expires_on' => 'date',
            'bytes' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $document) {
            if ($document->isDirty('number')) {
                $document->number_hash = $document->number === null ? null : self::numberHash($document->number);
            }
        });
    }

    /** HMAC for lookup and duplicate checks without decrypting every row. */
    public static function numberHash(string $number): string
    {
        return hash_hmac('sha256', strtoupper((string) preg_replace('/[\s-]+/', '', $number)), (string) config('app.key'));
    }

    /** Today in Dhaka, from the application clock (so it follows time travel in tests). */
    public static function today(): CarbonImmutable
    {
        return now('Asia/Dhaka')->startOfDay()->toImmutable();
    }

    public function status(?CarbonImmutable $today = null): string
    {
        if ($this->expires_on === null) {
            return self::NO_EXPIRY;
        }
        $today ??= self::today();
        $expires = $this->expires_on->toDateString();

        return match (true) {
            $expires < $today->toDateString() => self::EXPIRED,
            $expires <= $today->addDays(self::EXPIRING_DAYS)->toDateString() => self::EXPIRING,
            $expires <= $today->addDays(self::RENEW_SOON_DAYS)->toDateString() => self::RENEW_SOON,
            default => self::VALID,
        };
    }

    /** Documents on file: not archived. */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** The same ranges as status(), as a query. */
    public function scopeWithStatus(Builder $query, string $status, ?CarbonImmutable $today = null): void
    {
        $today ??= self::today();
        $day = fn (int $days) => $today->addDays($days)->toDateString();

        match ($status) {
            self::EXPIRED => $query->where('expires_on', '<', $today->toDateString()),
            self::EXPIRING => $query->whereBetween('expires_on', [$today->toDateString(), $day(self::EXPIRING_DAYS)]),
            self::RENEW_SOON => $query->where('expires_on', '>', $day(self::EXPIRING_DAYS))->where('expires_on', '<=', $day(self::RENEW_SOON_DAYS)),
            self::VALID => $query->where('expires_on', '>', $day(self::RENEW_SOON_DAYS)),
            self::NO_EXPIRY => $query->whereNull('expires_on'),
            self::ATTENTION => $query->whereNotNull('expires_on')->where('expires_on', '<=', $day(self::EXPIRING_DAYS))
                ->whereHas('staff', fn (Builder $staff) => $staff->where('status', '!=', StaffStatus::Suspended->value)),
        };
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'uploaded_by_staff_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'archived_by_staff_id');
    }
}
