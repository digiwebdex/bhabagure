<?php

namespace App\Console\Commands;

use App\Support\Database\DatabaseGuardTriggers;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class DatabaseGuardTriggersCommand extends Command
{
    protected $signature = 'db:guard-triggers {action=status : status, install or drop}';

    protected $description = 'Show, install or drop the optional database triggers that make ledgers append-only and issued invoices frozen';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status(),
            'install' => $this->install(),
            'drop' => $this->drop(),
            default => $this->invalid(),
        };
    }

    private function status(): int
    {
        $installed = DatabaseGuardTriggers::installed();
        $this->line('DB_GUARD_TRIGGERS: '.(DatabaseGuardTriggers::enabled() ? 'true' : 'false'));
        $this->line($installed === [] ? 'No guard triggers installed (the application layer enforces the rules).' : 'Installed: '.implode(', ', $installed));

        return self::SUCCESS;
    }

    private function install(): int
    {
        if (! DatabaseGuardTriggers::enabled()) {
            $this->error('Set DB_GUARD_TRIGGERS=true first. It needs trigger privileges the shared host may not grant.');

            return self::FAILURE;
        }

        try {
            DatabaseGuardTriggers::install();
        } catch (QueryException $exception) {
            $this->error('MySQL refused to create the triggers: '.$exception->getPrevious()?->getMessage());
            $this->line('With binary logging on, this needs SUPER or log_bin_trust_function_creators — a server-wide decision.');

            return self::FAILURE;
        }

        return $this->status();
    }

    private function drop(): int
    {
        DatabaseGuardTriggers::drop();

        return $this->status();
    }

    private function invalid(): int
    {
        $this->error('Action must be status, install or drop.');

        return self::INVALID;
    }
}
