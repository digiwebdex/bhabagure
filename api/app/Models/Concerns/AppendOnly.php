<?php

namespace App\Models\Concerns;

use App\Exceptions\LedgerImmutable;
use App\Models\Concerns\AppendOnly\AppendOnlyBuilder;

/**
 * Ledger rows are inserted once and never changed (see Support\Database\LedgerTables for the full rule).
 *
 * Model events stop `$row->update()` / `$row->delete()`; the builder stops mass
 * `Model::where(...)->update()` / `->delete()`, which never fire model events.
 */
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(fn (self $model) => throw new LedgerImmutable($model->getTable()));
        static::deleting(fn (self $model) => throw new LedgerImmutable($model->getTable()));
    }

    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        return new AppendOnlyBuilder($query);
    }
}
