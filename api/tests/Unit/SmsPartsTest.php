<?php

namespace Tests\Unit;

use App\Support\Sms\SmsParts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 3GPP TS 23.038 part counting, against fixtures computed independently of this class (tests/Fixtures/sms-parts.json):
 * the 160/153 and 70/67 boundaries, extension characters that cost two septets and can't be split from their escape,
 * emoji that take two UTF-16 units, Bangla, ৳, and a typical Bangla transactional message with a link.
 */
class SmsPartsTest extends TestCase
{
    #[Test]
    public function parts_encoding_and_units_match_the_fixtures(): void
    {
        $fixtures = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/sms-parts.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(30, count($fixtures));

        foreach ($fixtures as $fixture) {
            $result = SmsParts::count($fixture['text']);
            $this->assertSame(
                [$fixture['encoding'] === 'GSM-7' ? 'gsm7' : 'ucs2', $fixture['units'], $fixture['parts'], $fixture['partsGraphemeSafe'], max($fixture['parts'], $fixture['partsGraphemeSafe'])],
                [$result['encoding'], $result['units'], $result['parts_by_units'], $result['parts_grapheme_safe'], $result['parts']],
                "{$fixture['id']}: {$fixture['desc']}",
            );
        }
    }

    #[Test]
    public function the_ambiguous_gsm_0x09_cedilla_counts_as_unicode_so_it_is_never_undercounted(): void
    {
        $this->assertSame('ucs2', SmsParts::count('François')['encoding']);
        $this->assertSame('ucs2', SmsParts::count('FRANÇOIS')['encoding']);
        $this->assertSame('gsm7', SmsParts::count('Booking BH-2610-001 confirmed. Balance due BDT 1,03,000.')['encoding']);
    }

    #[Test]
    public function the_default_sms_templates_stay_within_warning_length_for_realistic_values(): void
    {
        $bangla = 'বুকিং BH-2610-001 নিশ্চিত। যাত্রা ৩১ অক্টোবর ২০২৬, বকেয়া ৳ ১,০৩,০০০। ইনভয়েস: https://api.bhabaghure.com.bd/i/AbC123xYz9';
        $english = 'Booking BH-2610-001 confirmed. Travel 31 October 2026, balance due BDT 1,03,000. Invoice: https://api.bhabaghure.com.bd/i/AbC123xYz9';

        $this->assertSame(['ucs2', 2], [SmsParts::count($bangla)['encoding'], SmsParts::count($bangla)['parts']]);
        $this->assertSame(['gsm7', 1], [SmsParts::count($english)['encoding'], SmsParts::count($english)['parts']]);
    }
}
