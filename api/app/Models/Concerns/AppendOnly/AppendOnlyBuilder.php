<?php

namespace App\Models\Concerns\AppendOnly;

use App\Exceptions\LedgerImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Query builder for append-only models: reads and inserts only. */
class AppendOnlyBuilder extends Builder
{
    public function update(array $values)
    {
        $this->refuse();
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->refuse();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuse();
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuse();
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        $this->refuse();
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        $this->refuse();
    }

    public function delete()
    {
        $this->refuse();
    }

    public function forceDelete()
    {
        $this->refuse();
    }

    private function refuse(): never
    {
        throw new LedgerImmutable($this->getModel()->getTable());
    }
}
