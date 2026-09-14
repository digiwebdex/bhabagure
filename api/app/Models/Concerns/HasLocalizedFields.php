<?php

namespace App\Models\Concerns;

/** Paired `*_bn` / `*_en` columns, read as one { bn, en } value. */
trait HasLocalizedFields
{
    /** @return array{bn: string, en: string} */
    public function localized(string $field): array
    {
        $bn = $this->getAttribute("{$field}_bn");
        $en = $this->getAttribute("{$field}_en");

        // The client hasn't supplied every Bangla text yet; fall back to the other language rather than blank.
        return ['bn' => (string) ($bn ?? $en ?? ''), 'en' => (string) ($en ?? $bn ?? '')];
    }

    /** @return array{bn: string, en: string}|null */
    public function localizedOrNull(string $field): ?array
    {
        if ($this->getAttribute("{$field}_bn") === null && $this->getAttribute("{$field}_en") === null) {
            return null;
        }

        return $this->localized($field);
    }
}
