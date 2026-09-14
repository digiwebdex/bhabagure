<?php

namespace App\Services\Passports;

/**
 * An OCR provider behind one method, so the provider can change (Textract today; Google Cloud Vision would be one more
 * class). Returns the text lines found, or null when the provider is not configured, fails or times out — the booking
 * then carries on with fields typed by hand.
 */
interface PassportTextReader
{
    public function name(): string;

    public function read(string $bytes, string $mime): ?string;
}
