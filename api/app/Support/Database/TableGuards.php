<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/** Schema constraints Laravel's builder has no API for. None of these need elevated MySQL privileges. */
final class TableGuards
{
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }
}
