<?php

namespace Tests\Unit;

use App\Support\Numerals;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Same cases as @bhabaghure/format's tests: the invoice and the admin must print identical strings. */
class NumeralsTest extends TestCase
{
    private static function fixtures(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../../packages/format/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function it_matches_every_shared_fixture(): void
    {
        $f = self::fixtures();
        foreach ($f['localizeDigits'] as $case) {
            $this->assertSame($case['expected'], Numerals::localizeDigits($case['text'], $case['locale']));
        }
        foreach ($f['formatNumber'] as $case) {
            $this->assertSame($case['expected'], Numerals::number($case['value'], $case['locale'], $case['options']['decimals'] ?? 'auto'), json_encode($case));
        }
        foreach ($f['formatBdt'] as $case) {
            $this->assertSame($case['expected'], Numerals::bdt($case['value'], $case['locale'], $case['options']['decimals'] ?? 'auto'), json_encode($case));
        }
        foreach ($f['formatPercent'] as $case) {
            $this->assertSame($case['expected'], Numerals::percent($case['value'], $case['locale']), json_encode($case));
        }
        foreach ($f['formatDate'] as $case) {
            $this->assertSame($case['expected'], Numerals::date($case['date'], $case['locale']));
        }
    }

    #[Test]
    public function it_refuses_what_the_typescript_version_refuses(): void
    {
        $f = self::fixtures();
        foreach ($f['invalidDates'] as $date) {
            $this->assertThrows(fn () => Numerals::date($date, 'en'), $date);
        }
        foreach ($f['invalid'] as $value) {
            $this->assertThrows(fn () => Numerals::bdt($value, 'en'), $value);
        }
    }

    private function assertThrows(callable $callback, string $label): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("Accepted invalid input \"{$label}\"");
    }
}
