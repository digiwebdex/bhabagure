<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A saved answer staff insert into an inbox reply (docs/admin-inbox.md). */
class CannedReply extends Model
{
    protected $fillable = ['title', 'body', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
