<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Double-entry journal. Append-only; a correction is a reversing entry. */
class JournalEntry extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['entry_date', 'description', 'source_type', 'source_id', 'booking_id', 'reverses_journal_entry_id', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Who posted it, for a staff journal entry (docs/phase-9-accounts.md §3). */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }
}
