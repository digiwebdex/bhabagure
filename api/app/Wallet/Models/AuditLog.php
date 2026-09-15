<?php

namespace App\Wallet\Models;

use App\Models\Concerns\AppendOnly;

/** The wallet's own audit trail, append-only, in the wallet database; nothing here reaches the company's audit log. */
class AuditLog extends WalletModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'wallet_audit_logs';

    protected $fillable = ['action', 'staff_id', 'subject_type', 'subject_id', 'changes', 'ip'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    /** @param array<string, mixed>|null $changes */
    public static function record(string $action, ?int $staffId, ?WalletModel $subject = null, ?array $changes = null): void
    {
        self::query()->create([
            'action' => $action,
            'staff_id' => $staffId,
            'subject_type' => $subject?->getTable(),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes,
            'ip' => request()?->ip(),
        ]);
    }
}
