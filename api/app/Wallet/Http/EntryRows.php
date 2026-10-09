<?php

namespace App\Wallet\Http;

use App\Wallet\Models\Transaction;

/**
 * How an entry reads on the wallet screen (docs/wallet-edits.md): its source or deal under the name it has now, whether
 * it stands or was edited or deleted (when, and why), and on a corrected entry what the edit changed.
 */
final class EntryRows
{
    /** The relations a row reads: load them with the entries. */
    public const WITH = [
        'deal:id,name,deleted_at',
        'source:id,name',
        'reversedBy:id,reverses_id,reason,created_at',
        'replacedBy:id,replaces_id',
        'replaces.deal:id,name,deleted_at',
        'replaces.source:id,name',
    ];

    /** @return array<string, mixed> */
    public static function row(Transaction $entry): array
    {
        $cancelled = $entry->reversedBy;
        $status = $cancelled === null ? 'standing' : ($entry->replacedBy !== null ? 'edited' : 'deleted');

        return [
            'id' => $entry->id,
            'direction' => $entry->direction,
            'amount' => (float) $entry->amount,
            'kind' => $entry->kind,
            'source' => self::sourceName($entry),
            'source_id' => $entry->source_id,
            'deal' => $entry->deal_id !== null ? ['id' => $entry->deal_id, 'name' => $entry->deal?->name, 'deleted' => $entry->deal?->deleted_at !== null] : null,
            'reference' => $entry->reference,
            'occurred_on' => $entry->occurred_on->toDateString(),
            'has_evidence' => $entry->evidence_path !== null,
            'evidence_name' => $entry->evidence_name,
            'created_at' => $entry->created_at?->toIso8601String(),
            // standing · edited (a corrected entry took its place) · deleted
            'status' => $status,
            'changed_at' => $cancelled?->created_at?->toIso8601String(),
            'change_reason' => $cancelled?->reason,
            'replaced_by_id' => $entry->replacedBy?->id,
            // On a corrected entry: the version it replaced, and what the edit changed.
            'replaces_id' => $entry->replaces_id,
            'changes' => $entry->replaces !== null ? self::changes($entry->replaces, $entry) : [],
        ];
    }

    /**
     * An entry with every version of it, oldest first: as first recorded, then each edit, the last one as it stands or
     * deleted.
     *
     * @return array<string, mixed>
     */
    public static function withVersions(Transaction $entry, int $owner): array
    {
        $first = $entry;
        while ($first->replaces_id !== null) {
            $first = self::find($first->replaces_id, $owner);
        }
        $versions = [];
        for ($version = $first; $version !== null; $version = $version->replacedBy ? self::find($version->replacedBy->id, $owner) : null) {
            $versions[] = self::row($version);
        }

        return self::row($entry) + ['versions' => $versions];
    }

    /**
     * What an edit changed, field by field. Evidence is compared by file: a new file is a change even under the same name.
     *
     * @return list<array{field: string, before: mixed, after: mixed}>
     */
    public static function changes(Transaction $before, Transaction $after): array
    {
        $pairs = [
            'direction' => [$before->direction, $after->direction],
            'amount' => [(float) $before->amount, (float) $after->amount],
            'source' => [self::sourceName($before), self::sourceName($after)],
            'reference' => [$before->reference, $after->reference],
            'occurred_on' => [$before->occurred_on->toDateString(), $after->occurred_on->toDateString()],
            'evidence' => [$before->evidence_path === null ? null : (string) $before->evidence_name, $after->evidence_path === null ? null : (string) $after->evidence_name],
        ];
        $changes = [];
        foreach ($pairs as $field => [$was, $is]) {
            if ($field === 'evidence' ? $before->evidence_path !== $after->evidence_path : $was !== $is) {
                $changes[] = ['field' => $field, 'before' => $was, 'after' => $is];
            }
        }

        return $changes;
    }

    /** Its deal's or source's name now; the name it was recorded under if that is gone. */
    public static function sourceName(Transaction $entry): string
    {
        if ($entry->deal_id !== null) {
            return $entry->deal?->name ?? $entry->source_name;
        }

        return $entry->source?->name ?? $entry->source_name;
    }

    private static function find(int $id, int $owner): Transaction
    {
        return Transaction::query()->ownedBy($owner)->with(self::WITH)->findOrFail($id);
    }
}
