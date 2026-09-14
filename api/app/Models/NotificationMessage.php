<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One outbound message: event × recipient × channel (docs/phase-1-schema.md §3.6, phase-4-whatsapp.md §4). The body
 * is stored exactly as sent. `dedupe_key` is unique, so a scheduler re-run or a repeated event can't send twice.
 * (Named NotificationMessage to stay clear of Laravel's own Notification classes; the table is `notifications`.)
 */
class NotificationMessage extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'event', 'channel', 'recipient_type', 'recipient_id', 'to_address', 'related_type', 'related_id', 'locale', 'title', 'body',
        'attachment_path', 'status', 'provider', 'provider_message_id', 'provider_whatsapp_id', 'attempts', 'last_error', 'skipped_reason',
        'triggered_by_staff_id', 'scheduled_for', 'sent_at', 'delivered_at', 'read_at', 'failed_at', 'dedupe_key',
        'group_key', 'fallback_of_id', 'sms_parts', 'sms_encoding', 'cost',
    ];

    protected function casts(): array
    {
        return [
            'event' => NotificationEvent::class,
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'attempts' => 'integer',
            'sms_parts' => 'integer',
            'cost' => 'decimal:2',
            'fallback_of_id' => 'integer',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    /** An invoice PDF to attach, stored as "invoice:{id}". */
    public function attachedInvoiceId(): ?int
    {
        return preg_match('/^invoice:(\d+)$/', (string) $this->attachment_path, $m) === 1 ? (int) $m[1] : null;
    }

    /** A quotation PDF to attach, stored as "quotation:{id}". */
    public function attachedQuotationId(): ?int
    {
        return preg_match('/^quotation:(\d+)$/', (string) $this->attachment_path, $m) === 1 ? (int) $m[1] : null;
    }
}
