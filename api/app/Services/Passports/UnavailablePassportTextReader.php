<?php

namespace App\Services\Passports;

/** No OCR provider configured (PASSPORT_OCR_PROVIDER=none): every scan is "unavailable, type manually". */
final class UnavailablePassportTextReader implements PassportTextReader
{
    public function name(): string
    {
        return 'none';
    }

    public function read(string $bytes, string $mime): ?string
    {
        return null;
    }
}
