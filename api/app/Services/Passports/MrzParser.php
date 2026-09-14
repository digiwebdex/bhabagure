<?php

namespace App\Services\Passports;

/**
 * Reads the machine-readable zone of a passport (ICAO 9303 TD3: two lines of 44 characters) out of OCR text and checks
 * every check digit. The printed fields on the page are ignored — the MRZ is what border systems read, and its check
 * digits tell us when OCR misread a character.
 *
 * A field whose check digit fails is still returned, flagged `confirm: true`, so the traveller is asked to check it
 * instead of it being silently accepted (decision of 2026-09-13).
 */
final class MrzParser
{
    private const LINE = 44;

    /** OCR confusions that are only safe to fix where the MRZ allows digits alone. */
    private const TO_DIGIT = ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'B' => '8', 'G' => '6'];

    /** ICAO nationality codes → ISO 3166 alpha-2 for the countries this business sees most. Others stay unmapped. */
    private const COUNTRIES = [
        'BGD' => 'BD', 'IND' => 'IN', 'NPL' => 'NP', 'BTN' => 'BT', 'LKA' => 'LK', 'PAK' => 'PK', 'MYS' => 'MY', 'SGP' => 'SG',
        'THA' => 'TH', 'CHN' => 'CN', 'GBR' => 'GB', 'USA' => 'US', 'CAN' => 'CA', 'AUS' => 'AU', 'ARE' => 'AE', 'SAU' => 'SA',
    ];

    /**
     * @return array{surname: string, givenNames: string, fullName: string, sex: ?string, nationality: array{value: string, iso2: ?string},
     *     passportNumber: array{value: string, confirm: bool}, dateOfBirth: array{value: ?string, confirm: bool},
     *     passportExpiry: array{value: ?string, confirm: bool}, compositeValid: bool}|null  null when no MRZ is found
     */
    public function parse(string $text): ?array
    {
        [$line1, $line2] = $this->findLines($text) ?? [null, null];
        if ($line1 === null) {
            return null;
        }

        $number = substr($line2, 0, 9);
        $numberCheck = $this->digit($line2[9]);
        $nationality = str_replace('<', '', substr($line2, 10, 3));
        $dob = $this->digits(substr($line2, 13, 6));
        $dobCheck = $this->digit($line2[19]);
        $sex = $line2[20];
        $expiry = $this->digits(substr($line2, 21, 6));
        $expiryCheck = $this->digit($line2[27]);
        $personal = substr($line2, 28, 14);
        $personalCheck = $line2[42] === '<' ? '0' : $this->digit($line2[42]);
        $composite = $this->digit($line2[43]);

        $numberValid = self::checkDigit($number) === $numberCheck;
        $dobValid = self::checkDigit($dob) === $dobCheck;
        $expiryValid = self::checkDigit($expiry) === $expiryCheck;
        $personalValid = str_replace('<', '', $personal) === '' ? true : self::checkDigit($personal) === $personalCheck;
        $compositeValue = $number.$numberCheck.$dob.$dobCheck.$expiry.$expiryCheck.$personal.$personalCheck;

        [$surname, $given] = array_pad(explode('<<', substr($line1, 5), 2), 2, '');
        $surname = trim(str_replace('<', ' ', $surname));
        $given = trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $given)));
        $dobDate = $this->date($dob, past: true);
        $expiryDate = $this->date($expiry, past: false);

        return [
            'surname' => $this->title($surname),
            'givenNames' => $this->title($given),
            'fullName' => trim($this->title($given).' '.$this->title($surname)),
            'sex' => in_array($sex, ['M', 'F'], true) ? $sex : null,
            'nationality' => ['value' => $nationality, 'iso2' => self::COUNTRIES[$nationality] ?? null],
            'passportNumber' => ['value' => str_replace('<', '', $number), 'confirm' => ! $numberValid],
            'dateOfBirth' => ['value' => $dobDate, 'confirm' => ! $dobValid || $dobDate === null],
            'passportExpiry' => ['value' => $expiryDate, 'confirm' => ! $expiryValid || $expiryDate === null],
            'compositeValid' => $personalValid && self::checkDigit($compositeValue) === $composite,
        ];
    }

    /** ICAO 9303 check digit: weights 7-3-1 over digits (0–9), letters (A=10 … Z=35) and fillers (< = 0). */
    public static function checkDigit(string $value): string
    {
        $weights = [7, 3, 1];
        $sum = 0;
        foreach (str_split($value) as $i => $char) {
            $n = match (true) {
                ctype_digit($char) => (int) $char,
                ctype_upper($char) => ord($char) - 55,
                default => 0,
            };
            $sum += $n * $weights[$i % 3];
        }

        return (string) ($sum % 10);
    }

    /** @return array{0: string, 1: string}|null */
    private function findLines(string $text): ?array
    {
        $lines = array_values(array_filter(array_map(
            fn (string $line) => strtoupper(str_replace([' ', '«', '‹', '|'], ['', '<', '<', ''], trim($line))),
            preg_split('/\R/u', $text) ?: [],
        ), fn (string $line) => strlen($line) >= self::LINE - 2 && preg_match('/^[A-Z0-9<]+$/', $line) === 1));

        for ($i = 0; $i < count($lines) - 1; $i++) {
            if (str_starts_with($lines[$i], 'P')) {
                $line1 = str_pad(substr($lines[$i], 0, self::LINE), self::LINE, '<');
                $line2 = str_pad(substr($lines[$i + 1], 0, self::LINE), self::LINE, '<');
                if (preg_match('/^[A-Z0-9<]{9}[0-9A-Z<][A-Z<]{3}/', $line2) === 1) {
                    return [$line1, $line2];
                }
            }
        }

        return null;
    }

    private function digits(string $value): string
    {
        return strtr($value, self::TO_DIGIT);
    }

    private function digit(string $char): string
    {
        return strtr($char, self::TO_DIGIT);
    }

    /** YYMMDD → Y-m-d. Birth dates are in the past; expiry dates are this century. */
    private function date(string $yymmdd, bool $past): ?string
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m) !== 1) {
            return null;
        }
        $year = 2000 + (int) $m[1];
        if ($past && $year > (int) now()->format('Y')) {
            $year -= 100;
        }

        return checkdate((int) $m[2], (int) $m[3], $year) ? sprintf('%04d-%s-%s', $year, $m[2], $m[3]) : null;
    }

    private function title(string $name): string
    {
        return mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
    }
}
