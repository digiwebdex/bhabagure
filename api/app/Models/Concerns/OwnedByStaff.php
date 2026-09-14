<?php

namespace App\Models\Concerns;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/phase-5-admin-core.md §0: a record belongs to the staff member in `assigned_staff_id` — whoever created it, a
 * claimant from the shared pool, or whoever an admin reassigned it to. Commission follows the owner, so ownership only
 * changes through App\Services\Admin\Ownership (audited).
 *
 * Visibility, the one rule every list, count, search and detail endpoint uses:
 * - staff who see everything of this kind see every row;
 * - staff who see their own see rows they own, plus the pool: unowned rows that are still open to claim;
 * - everyone else sees nothing.
 */
trait OwnedByStaff
{
    /** Whether this staff member sees every record of this kind. */
    abstract public static function seesAll(Staff $staff): bool;

    /** Whether this staff member may see their own records and the pool. */
    abstract public static function seesOwn(Staff $staff): bool;

    /** Unowned rows that are open to claim — the shared pool. */
    abstract public function scopeClaimable(Builder $query): void;

    public function scopeVisibleTo(Builder $query, Staff $staff): void
    {
        if (static::seesAll($staff)) {
            return;
        }
        if (! static::seesOwn($staff)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(fn (Builder $visible) => $visible
            ->where($this->qualifyColumn('assigned_staff_id'), $staff->id)
            ->orWhere(fn (Builder $pool) => $pool->claimable())
            ->when(method_exists($this, 'alsoVisibleToOwner'), fn (Builder $q) => $this->alsoVisibleToOwner($q, $staff)));
    }

    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('assigned_staff_id'));
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_staff_id');
    }
}
