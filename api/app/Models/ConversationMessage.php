<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in an inbox conversation (docs/admin-inbox.md). */
class ConversationMessage extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    /** Who wrote it: the customer, staff in the admin, someone on the phone / Page inbox, or an automated message. */
    public const CUSTOMER = 'customer';

    public const STAFF = 'staff';

    public const PHONE = 'phone';

    public const AUTOMATED = 'automated';

    /** Delivery: an incoming message is `received`; an outgoing one moves pending → sent → delivered → read, or failed. */
    public const STATUSES = ['received', 'pending', 'sent', 'delivered', 'read', 'failed'];

    public const KINDS = ['image', 'document', 'audio', 'video', 'sticker'];

    protected $fillable = [
        'conversation_id', 'direction', 'origin', 'staff_id', 'body', 'attachment_kind', 'attachment_path', 'attachment_mime',
        'attachment_name', 'attachment_bytes', 'attachment_source', 'provider_message_id', 'external_message_id', 'status', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attachment_source' => 'array',
            'attachment_bytes' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** A status only moves forward: a late "sent" never undoes "read"; a failure after delivery is ignored. */
    public function advanceTo(string $status): void
    {
        $rank = array_flip(['pending', 'sent', 'delivered', 'read']);
        if ($status === 'failed') {
            if (in_array($this->status, ['pending', 'sent'], true)) {
                $this->forceFill(['status' => 'failed'])->save();
            }

            return;
        }
        if (isset($rank[$status], $rank[$this->status]) && $rank[$status] > $rank[$this->status]) {
            $this->forceFill(['status' => $status])->save();
        }
    }

    /** What the conversation list shows for it: the text, else what was attached. */
    public function preview(): string
    {
        $text = trim((string) $this->body);
        if ($text !== '') {
            return mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 200);
        }

        return match ($this->attachment_kind) {
            'image' => '📷 Photo',
            'document' => '📄 '.($this->attachment_name ?: 'Document'),
            'audio' => '🎤 Voice message',
            'video' => '🎬 Video',
            'sticker' => 'Sticker',
            default => '…',
        };
    }
}
