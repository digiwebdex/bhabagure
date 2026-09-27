<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One customer's chat on one channel, in the admin inbox (docs/admin-inbox.md). */
class Conversation extends Model
{
    public const WHATSAPP = 'whatsapp';

    public const MESSENGER = 'messenger';

    public const CHANNELS = [self::WHATSAPP, self::MESSENGER];

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /** Messenger: a page may answer only within 24 hours of the customer's last message. */
    public const MESSENGER_WINDOW_HOURS = 24;

    protected $fillable = [
        'channel', 'external_id', 'name', 'phone', 'jid', 'lid', 'customer_id', 'assigned_staff_id', 'status', 'unread_count',
        'last_message_at', 'last_message_preview', 'last_message_direction', 'last_incoming_at',
    ];

    protected function casts(): array
    {
        return [
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_incoming_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_staff_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    /**
     * The inbox's filters: channel, unread, mine, open/closed and a search on name, number or last message.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFiltered(Builder $query, array $filters, Staff $staff): Builder
    {
        $view = (string) ($filters['view'] ?? 'all');

        return $query
            ->when(in_array($filters['channel'] ?? null, self::CHANNELS, true), fn (Builder $q) => $q->where('channel', $filters['channel']))
            ->when($view === 'unread', fn (Builder $q) => $q->where('unread_count', '>', 0)->where('status', self::OPEN))
            ->when($view === 'mine', fn (Builder $q) => $q->where('assigned_staff_id', $staff->id))
            ->when($view === 'closed', fn (Builder $q) => $q->where('status', self::CLOSED), fn (Builder $q) => $view === 'all' || $view === 'mine' ? $q->where('status', self::OPEN) : $q)
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $q) use ($filters) {
                $term = trim((string) $filters['search']);
                $digits = preg_replace('/\D/', '', $term);
                $q->where(function (Builder $w) use ($term, $digits) {
                    $w->where('name', 'like', "%{$term}%")->orWhere('last_message_preview', 'like', "%{$term}%");
                    if (strlen((string) $digits) >= 4) {
                        $w->orWhere('phone', 'like', '%'.ltrim((string) $digits, '0').'%');
                    }
                });
            });
    }

    /** Whether staff may still answer a Messenger chat (always for WhatsApp). */
    public function canReply(): bool
    {
        return $this->channel !== self::MESSENGER
            || ($this->last_incoming_at !== null && $this->last_incoming_at->gt(now()->subHours(self::MESSENGER_WINDOW_HOURS)));
    }
}
