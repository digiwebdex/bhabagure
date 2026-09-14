<?php

namespace App\Support\Barcode;

use InvalidArgumentException;

/**
 * Code 128, code set B (printable ASCII), with its modulo-103 check symbol, drawn as SVG so it stays sharp in print.
 * Office USB scanners read it. Verified by decoding the rasterised invoice PDF (tests/Feature/InvoicePdfTest), not only
 * by checking the pattern table.
 */
final class Code128
{
    /** Bar/space widths in modules for symbol values 0–106 (103–105 start codes, 106 stop). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    private const QUIET_MODULES = 10;

    /** @return list<int> symbol values: start, data, check, stop */
    public static function symbols(string $text): array
    {
        if ($text === '' || preg_match('/^[\x20-\x7E]+$/', $text) !== 1) {
            throw new InvalidArgumentException('Code 128 set B encodes printable ASCII only.');
        }

        $values = array_map(fn (string $char) => ord($char) - 32, str_split($text));
        $sum = self::START_B;
        foreach ($values as $i => $value) {
            $sum += $value * ($i + 1);
        }

        return [self::START_B, ...$values, $sum % 103, self::STOP];
    }

    /** Alternating bar and space widths, starting with a bar. */
    public static function widths(string $text): array
    {
        $widths = [];
        foreach (self::symbols($text) as $symbol) {
            array_push($widths, ...array_map('intval', str_split(self::PATTERNS[$symbol])));
        }

        return $widths;
    }

    /** Inline SVG sized in millimetres, quiet zones included. */
    public static function svg(string $text, float $moduleMm = 0.3, float $heightMm = 11): string
    {
        $x = self::QUIET_MODULES;
        $rects = '';
        foreach (self::widths($text) as $i => $width) {
            if ($i % 2 === 0) {
                $rects .= '<rect x="'.$x.'" y="0" width="'.$width.'" height="1"/>';
            }
            $x += $width;
        }
        $total = $x + self::QUIET_MODULES;
        $label = htmlspecialchars($text, ENT_QUOTES);

        return '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="'.$label.'" width="'.round($total * $moduleMm, 2).'mm" height="'.$heightMm.'mm"'
            .' viewBox="0 0 '.$total.' 1" preserveAspectRatio="none" shape-rendering="crispEdges" fill="#0F1E3A">'.$rects.'</svg>';
    }
}
