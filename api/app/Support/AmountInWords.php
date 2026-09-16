<?php

namespace App\Support;

/**
 * The amount an invoice comes to, written out (docs/phase-9-accounts.md §5). An invoice is a demand for money and the
 * figure is written twice so a changed digit shows: "৳ 1,75,000" and "One Lakh Seventy Five Thousand Taka Only".
 *
 * Counted the way Bangladesh counts — thousand, lakh, crore — because that is how the figure above it is grouped.
 */
final class AmountInWords
{
    private const ONES = [
        'Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = [2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'];

    /** "175000" → "One Lakh Seventy Five Thousand Taka Only"; paisa are said only when there are any. */
    public static function taka(int|float|string $amount): string
    {
        $paisa = (int) round((float) $amount * 100);
        $negative = $paisa < 0;
        $paisa = abs($paisa);
        $taka = intdiv($paisa, 100);
        $rest = $paisa % 100;

        $words = self::words($taka).' Taka';
        if ($rest > 0) {
            $words .= ' and '.self::words($rest).' Paisa';
        }

        return ($negative ? 'Minus ' : '').$words.' Only';
    }

    /** The number itself, with no currency: crore, lakh, thousand, hundred, then the last two digits. */
    private static function words(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }
        if ($number < 100) {
            return self::TENS[intdiv($number, 10)].($number % 10 ? ' '.self::ONES[$number % 10] : '');
        }

        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $size => $name) {
            if ($number >= $size) {
                $rest = $number % $size;

                return self::words(intdiv($number, $size))." {$name}".($rest ? ' '.self::words($rest) : '');
            }
        }

        return self::ONES[$number];
    }
}
